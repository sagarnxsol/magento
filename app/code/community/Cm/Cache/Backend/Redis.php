<?php

/**
 * @see Zend_Cache_Backend
 */
#require_once 'Zend/Cache/Backend.php';

/**
 * @see Zend_Cache_Backend_ExtendedInterface
 */
#require_once 'Zend/Cache/Backend/ExtendedInterface.php';

/**
 * Redis adapter for Zend_Cache
 *
 * @author Colin Mollenhour
 */
class Cm_Cache_Backend_Redis extends Zend_Cache_Backend implements Zend_Cache_Backend_ExtendedInterface
{

    const SET_IDS         = 'zc:ids';
    const SET_TAGS        = 'zc:tags';

    const PREFIX_KEY      = 'zc:k:';
    const PREFIX_TAG_IDS  = 'zc:ti:';

    const FIELD_DATA      = 'd';
    const FIELD_MTIME     = 'm';
    const FIELD_TAGS      = 't';
    const FIELD_INF       = 'i';

    const STALE = "STALE";
    const LOCK = "LOCK";


    const MAX_LIFETIME    = 2592000; /* Redis backend limit */
    const COMPRESS_PREFIX = ":\x1f\x8b";
    const DEFAULT_CONNECT_TIMEOUT = 2.5;
    const DEFAULT_CONNECT_RETRIES = 1;

    /** @var Credis_Client */
    protected $_redis;
    protected $pipe_count = 0;

    /** @var bool */
    protected $_notMatchingTags = FALSE;

    /** @var int */
    protected $_lifetimelimit = self::MAX_LIFETIME; /* Redis backend limit */

    /** @var int */
    protected $_compressTags = 1;

    /** @var int */
    protected $_compressData = 1;

    /** @var int */
    protected $_compressThreshold = 20480;

    /** @var string */
    protected $_compressionLib;

    protected $locks = array();
    /**
     * Contruct Zend_Cache Redis backend
     * @param array $options
     * @return \Cm_Cache_Backend_Redis
     */
    public function __construct($options = array())
    {
        if ( empty($options['server']) ) {
            Zend_Cache::throwException('Redis \'server\' not specified.');
        }

        if ( empty($options['port']) && substr($options['server'],0,1) != '/' ) {
            Zend_Cache::throwException('Redis \'port\' not specified.');
        }

        $timeout = isset($options['timeout']) ? $options['timeout'] : self::DEFAULT_CONNECT_TIMEOUT;
        $persistent = isset($options['persistent']) ? $options['persistent'] : '';
        $this->_redis = new Credis_Client($options['server'], $options['port'], $timeout, $persistent);

        if ( isset($options['force_standalone']) && $options['force_standalone']) {
            $this->_redis->forceStandalone();
        }

        $connectRetries = isset($options['connect_retries']) ? (int)$options['connect_retries'] : self::DEFAULT_CONNECT_RETRIES;
        $this->_redis->setMaxConnectRetries($connectRetries);

        if ( ! empty($options['password'])) {
            $this->_redis->auth($options['password']) or Zend_Cache::throwException('Unable to authenticate with the redis server.');
        }

        if ( ! empty($options['database'])) {
            $this->_redis->select( (int) $options['database']) or Zend_Cache::throwException('The redis database could not be selected.');
        }

        if ( isset($options['notMatchingTags']) ) {
            $this->_notMatchingTags = (bool) $options['notMatchingTags'];
        }

        if ( isset($options['compress_tags'])) {
            $this->_compressTags = (int) $options['compress_tags'];
        }

        if ( isset($options['compress_data'])) {
            $this->_compressData = (int) $options['compress_data'];
        }

        if ( isset($options['lifetimelimit'])) {
            $this->_lifetimelimit = (int) min($options['lifetimelimit'], self::MAX_LIFETIME);
        }

        if ( isset($options['compress_threshold'])) {
            $this->_compressThreshold = (int) $options['compress_threshold'];
        }

        if ( isset($options['automatic_cleaning_factor']) ) {
            $this->_options['automatic_cleaning_factor'] = (int) $options['automatic_cleaning_factor'];
        } else {
            $this->_options['automatic_cleaning_factor'] = 0;
        }

        if ( isset($options['compression_lib']) ) {
            $this->_compressionLib = $options['compression_lib'];
        }
        else if ( function_exists('snappy_compress') ) {
            $this->_compressionLib = 'snappy';
        }
        else if ( function_exists('lzf_compress') ) {
            $this->_compressionLib = 'lzf';
        }
        else {
            $this->_compressionLib = 'gzip';
        }
        $this->_compressPrefix = substr($this->_compressionLib,0,2).self::COMPRESS_PREFIX;
    }

    /**
     * Load value with given id from cache
     *
     * @param  string  $id                     Cache id
     * @param  boolean $doNotTestCacheValidity If set to true, the cache validity won't be tested
     * @return bool|string
     */
    public function _load($id, $doNotTestCacheValidity = false)
    {
        $data = $this->_redis->hGet(self::PREFIX_KEY.$id, self::FIELD_DATA);
        if ($data === NULL) {
            return FALSE;
        }
        return $this->_decodeData($data);
    }

    public function save($data, $id = null, $tags = array(), $specificLifetime = false, $priority = 8)
    {
        //Mage::log("Saving $id");
        $res =  $this->_save($data, $id, $tags, $specificLifetime, $priority);
        $this->unlock($id."-".self::LOCK);
        $this->_redis->del(self::PREFIX_KEY.$id. "-".self::STALE);
        return $res;
    }
    public function invalidateAll($ids){
        $this->pipeline();
        foreach($ids as $id){
            $this->invalidate($id);
        }
        $this->exec_pipeline();
    }
    public function invalidate($id){
        $this->pipeline();
//        Mage::log("Invalidating $id");
        $this->_redis->del(self::PREFIX_KEY.$id. "-".self::LOCK); //Remove the lock, the cached element probably needs recaching again
        $this->_redis->hSetNx(self::PREFIX_KEY.$id, self::FIELD_DATA, false); //Set something to the value, if it was already deleted
        $this->_redis->renameNx(self::PREFIX_KEY.$id, self::PREFIX_KEY.$id. "-".self::STALE); //Rename if the new one does not exist
        $this->_redis->del(self::PREFIX_KEY.$id); //Delete the old key, in case the rename didn't work
        $this->_redis->expire(self::PREFIX_KEY.$id. "-".self::STALE, 300);   //Add an expires to the new key to make sure it will go away
        $this->exec_pipeline();
    }
    public function remove($id, $level = 0){
        switch($level){
            case 0:
            case 1:
                $this->invalidate($id);
                break;
            case 2:
                $this->_remove($id);
                break;
        }
    }

    public function load($id, $doNotTestCacheValidity = false, $doNotUnserialize = false){
        if (isset($this->locks[$id."-".self::LOCK])) {return false;}
        $data = $this->_load($id, $doNotTestCacheValidity, $doNotUnserialize);
        if ($data) {return $data; }//Data is cached, returning...
        $data_stale = $this->_load($id."-".self::STALE, $doNotTestCacheValidity, $doNotUnserialize);
        if (!$data_stale) {return false; }//No stale data was found.
        $lock = $this->lock($id."-".self::LOCK, 120); //Ok, need to refresh data. Can I get the lock?
        if ($lock) {
            //Mage::log("Locked $id");
            return false; //Got the lock, regen the data
        } else {
            //Mage::log("Loading Stale $id");
            return $data_stale; //Someone else got the lock, return stale data
        }


    }

    /**
     * Test if a cache is available or not (for the given id)
     *
     * @param  string $id Cache id
     * @return bool|int False if record is not available or "last modified" timestamp of the available cache record
     */
    public function test($id)
    {
        $mtime = $this->_redis->hGet(self::PREFIX_KEY.$id, self::FIELD_MTIME);
        return ($mtime ? $mtime : FALSE);
    }

    public function isLocked($id){
        return $this->_redis->exists(self::PREFIX_KEY.$id);
    }
    public function lock($id, $time=300){
         if (isset($this->locks[$id])){
             return true; //We already have that lock
         }
         $lock = $this->_redis->setNx(self::PREFIX_KEY.$id, true);
         if ($lock){
            $this->locks[$id] = true;
            $this->_redis->expire(self::PREFIX_KEY.$id, $time);
         }
         return $lock;
    }
    public function unlock($id){
         $lock = $this->_redis->del(self::PREFIX_KEY.$id);
         unset($this->locks[$id]);
         return $lock;
    }
    /**
     * Save some string datas into a cache record
     *
     * Note : $data is always "string" (serialization is done by the
     * core not by the backend)
     *
     * @param  string $data             Datas to cache
     * @param  string $id               Cache id
     * @param  array  $tags             Array of strings, the cache record will be tagged by each string entry
     * @param  bool|int $specificLifetime If != false, set a specific lifetime for this cache record (null => infinite lifetime)
     * @throws CredisException
     * @return boolean True if no problem
     */
    public function _save($data, $id, $tags = array(), $specificLifetime = false)
    {
        if(!is_array($tags)) $tags = array($tags);

        $lifetime = $this->getLifetime($specificLifetime);

        // Get list of tags previously assigned
        $oldTags = $this->_decodeData($this->_redis->hGet(self::PREFIX_KEY.$id, self::FIELD_TAGS));
        $oldTags = $oldTags ? explode(',', $oldTags) : array();

        $this->pipeline();

        // Set the data
        $result = $this->_redis->hMSet(self::PREFIX_KEY.$id, array(
          self::FIELD_DATA => $this->_encodeData($data, $this->_compressData),
          self::FIELD_TAGS => $this->_encodeData(implode(',',$tags), $this->_compressTags),
          self::FIELD_MTIME => time(),
          self::FIELD_INF => $lifetime ? 0 : 1,
        ));
        if( ! $result) {
            throw new CredisException("Could not set cache key $id");
        }

        // Always expire so the volatile-* eviction policies may be safely used, otherwise
        // there is a risk that tag data could be evicted.
        $this->_redis->expire(self::PREFIX_KEY.$id, $lifetime ? $lifetime : $this->_lifetimelimit);

        // Process added tags
        if ($addTags = ($oldTags ? array_diff($tags, $oldTags) : $tags))
        {
            // Update the list with all the tags
            $this->_redis->sAdd( self::SET_TAGS, $addTags);

            // Update the id list for each tag
            foreach($addTags as $tag)
            {
                $this->_redis->sAdd(self::PREFIX_TAG_IDS . $tag, $id);
            }
        }

        // Process removed tags
        if ($remTags = ($oldTags ? array_diff($oldTags, $tags) : FALSE))
        {
            // Update the id list for each tag
            foreach($remTags as $tag)
            {
                $this->_redis->sRem(self::PREFIX_TAG_IDS . $tag, $id);
            }
        }

        // Update the list with all the ids
        if($this->_notMatchingTags) {
            $this->_redis->sAdd(self::SET_IDS, $id);
        }

        $this->exec_pipeline();

        return TRUE;
    }
    public function pipeline(){
        if ($this->pipe_count==0){
            $this->_redis->pipeline()->multi();
        }
        $this->pipe_count++;
    }
    public function exec_pipeline(){
        $this->pipe_count--;
        if ($this->pipe_count==0){
            $result = $this->_redis->exec();
            
            return isset($result[0]) ? true : false;
        }
        return true;
    }

    /**
     * Remove a cache record
     *
     * @param  string $id Cache id
     * @return boolean True if no problem
     */
    public function _remove($id)
    {
        // Get list of tags for this id
        $tags = explode(',', $this->_decodeData($this->_redis->hGet(self::PREFIX_KEY.$id, self::FIELD_TAGS)));

        $this->pipeline();

        // Remove data
        $this->_redis->del(self::PREFIX_KEY.$id);

        // Remove id from list of all ids
        if($this->_notMatchingTags) {
            $this->_redis->sRem( self::SET_IDS, $id );
        }

        // Update the id list for each tag
        foreach($tags as $tag) {
            $this->_redis->sRem(self::PREFIX_TAG_IDS . $tag, $id);
        }

        $result = $this->exec_pipeline();

        return $result;
    }

    /**
     * @param array $tags
     */
    protected function _removeByNotMatchingTags($tags)
    {
        $ids = $this->getIdsNotMatchingTags($tags);
        if($ids)
        {
            $this->pipeline();

            // Remove data
            $this->invalidateAll( $ids);

            // Remove ids from list of all ids
            if($this->_notMatchingTags) {
                $this->_redis->sRem( self::SET_IDS, $ids);
            }

            $this->exec_pipeline();
        }
    }

    /**
     * @param array $tags
     */
    protected function _removeByMatchingTags($tags)
    {
        $ids = $this->getIdsMatchingTags($tags);
        if($ids)
        {
            $this->pipeline();

            // Remove data
            $this->invalidateAll( $ids);

//            // Remove ids from list of all ids
//            if($this->_notMatchingTags) {
//                $this->_redis->sRem( self::SET_IDS, $ids);
//            }

            $this->exec_pipeline();
        }
    }

    /**
     * @param array $tags
     */
    protected function _removeByMatchingAnyTags($tags)
    {
        $ids = $this->getIdsMatchingAnyTags($tags);

        $this->pipeline();

        if($ids)
        {
            // Remove data
            $this->invalidateAll( $ids);

//            // Remove ids from list of all ids
//            if($this->_notMatchingTags) {
//                $this->_redis->sRem( self::SET_IDS, $ids);
//            }
        }

//        // Remove tag id lists
//        $this->_redis->del( $this->_preprocessTagIds($tags));
//
//        // Remove tags from list of tags
//        $this->_redis->sRem( self::SET_TAGS, $tags);

        $this->exec_pipeline();
    }

    /**
     * Clean up tag id lists since as keys expire the ids remain in the tag id lists
     */
    protected function _collectGarbage()
    {
        // Clean up expired keys from tag id set and global id set
        $exists = array();
        $tags = (array) $this->_redis->sMembers(self::SET_TAGS);
        foreach($tags as $tag)
        {
            // Get list of expired ids for each tag
            $tagMembers = $this->_redis->sMembers(self::PREFIX_TAG_IDS . $tag);
            $numTagMembers = count($tagMembers);
            $expired = array();
            $numExpired = $numNotExpired = 0;
            if($numTagMembers) {
                while ($id = array_pop($tagMembers)) {
                    if( ! isset($exists[$id])) {
                        $exists[$id] = $this->_redis->exists(self::PREFIX_KEY.$id);
                    }
                    if ($exists[$id]) {
                        $numNotExpired++;
                    }
                    else {
                        $numExpired++;
                        $expired[] = $id;

                        // Remove incrementally to reduce memory usage
                        if (count($expired) % 100 == 0 && $numNotExpired > 0) {
                            $this->_redis->sRem( self::PREFIX_TAG_IDS . $tag, $expired);
                            if($this->_notMatchingTags) { // Clean up expired ids from ids set
                                $this->_redis->sRem( self::SET_IDS, $expired);
                            }
                            $expired = array();
                        }
                    }
                }
                if( ! count($expired)) continue;
            }

            // Remove empty tags or completely expired tags
            if ($numExpired == $numTagMembers) {
                $this->_redis->del(self::PREFIX_TAG_IDS . $tag);
                $this->_redis->sRem(self::SET_TAGS, $tag);
            }
            // Clean up expired ids from tag ids set
            else if (count($expired)) {
                $this->_redis->sRem( self::PREFIX_TAG_IDS . $tag, $expired);
                if($this->_notMatchingTags) { // Clean up expired ids from ids set
                    $this->_redis->sRem( self::SET_IDS, $expired);
                }
            }
            unset($expired);
        }

        // Clean up global list of ids for ids with no tag
        if($this->_notMatchingTags) {
            // TODO
        }
    }

    /**
     * Clean some cache records
     *
     * Available modes are :
     * 'all' (default)  => remove all cache entries ($tags is not used)
     * 'old'            => unsupported
     * 'matchingTag'    => supported
     * 'notMatchingTag' => supported
     * 'matchingAnyTag' => supported
     *
     * @param  string $mode Clean mode
     * @param  array  $tags Array of tags
     * @throws Zend_Cache_Exception
     * @return boolean True if no problem
     */
    public function clean($mode = Zend_Cache::CLEANING_MODE_ALL, $tags = array())
    {
        if( $tags && ! is_array($tags)) {
            $tags = array($tags);
        }

        if($mode == Zend_Cache::CLEANING_MODE_ALL) {
            return $this->_redis->flushDb();
        }

        if($mode == Zend_Cache::CLEANING_MODE_OLD) {
            $this->_collectGarbage();
            return TRUE;
        }

        if( ! count($tags)) {
            return TRUE;
        }

        $result = TRUE;

        switch ($mode)
        {
            case Zend_Cache::CLEANING_MODE_MATCHING_TAG:

                $this->_removeByMatchingTags($tags);
                break;

            case Zend_Cache::CLEANING_MODE_NOT_MATCHING_TAG:

                $this->_removeByNotMatchingTags($tags);
                break;

            case Zend_Cache::CLEANING_MODE_MATCHING_ANY_TAG:

                $this->_removeByMatchingAnyTags($tags);
                break;

            default:
                Zend_Cache::throwException('Invalid mode for clean() method: '.$mode);
        }
        return (bool) $result;
    }

    /**
     * Return true if the automatic cleaning is available for the backend
     *
     * @return boolean
     */
    public function isAutomaticCleaningAvailable()
    {
        return TRUE;
    }

    /**
     * Set the frontend directives
     *
     * @param  array $directives Assoc of directives
     * @throws Zend_Cache_Exception
     * @return void
     */
    public function setDirectives($directives)
    {
        parent::setDirectives($directives);
        $lifetime = $this->getLifetime(false);
        if ($lifetime > self::MAX_LIFETIME) {
            Zend_Cache::throwException('Redis backend has a limit of 30 days (2592000 seconds) for the lifetime');
        }
    }

    /**
     * Return an array of stored cache ids
     *
     * @return array array of stored cache ids (string)
     */
    public function getIds()
    {
        if($this->_notMatchingTags) {
            return (array) $this->_redis->sMembers(self::SET_IDS);
        } else {
            $keys = $this->_redis->keys(self::PREFIX_KEY . '*');
            $prefixLen = strlen(self::PREFIX_KEY);
            foreach($keys as $index => $key) {
                $keys[$index] = substr($key, $prefixLen);
            }
            return $keys;
        }
    }

    /**
     * Return an array of stored tags
     *
     * @return array array of stored tags (string)
     */
    public function getTags()
    {
        return (array) $this->_redis->sMembers(self::SET_TAGS);
    }

    /**
     * Return an array of stored cache ids which match given tags
     *
     * In case of multiple tags, a logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of matching cache ids (string)
     */
    public function getIdsMatchingTags($tags = array())
    {
        if ($tags) {
            return (array) $this->_redis->sInter( $this->_preprocessTagIds($tags) );
        }
        return array();
    }

    /**
     * Return an array of stored cache ids which don't match given tags
     *
     * In case of multiple tags, a negated logical AND is made between tags
     *
     * @param array $tags array of tags
     * @return array array of not matching cache ids (string)
     */
    public function getIdsNotMatchingTags($tags = array())
    {
        if( ! $this->_notMatchingTags) {
            Zend_Cache::throwException("notMatchingTags is currently disabled.");
        }
        if ($tags) {
            return (array) $this->_redis->sDiff( self::SET_IDS, $this->_preprocessTagIds($tags) );
        }
        return (array) $this->_redis->sMembers( self::SET_IDS );
    }

    /**
     * Return an array of stored cache ids which match any given tags
     *
     * In case of multiple tags, a logical OR is made between tags
     *
     * @param array $tags array of tags
     * @return array array of any matching cache ids (string)
     */
    public function getIdsMatchingAnyTags($tags = array())
    {
        if ($tags) {
            return (array) $this->_redis->sUnion( $this->_preprocessTagIds($tags));
        }
        return array();
    }

    /**
     * Return the filling percentage of the backend storage
     *
     * @throws Zend_Cache_Exception
     * @return int integer between 0 and 100
     */
    public function getFillingPercentage()
    {
        return 0;
    }

    /**
     * Return an array of metadatas for the given cache id
     *
     * The array must include these keys :
     * - expire : the expire timestamp
     * - tags : a string array of tags
     * - mtime : timestamp of last modification time
     *
     * @param string $id cache id
     * @return array array of metadatas (false if the cache id is not found)
     */
    public function getMetadatas($id)
    {
        list($tags, $mtime, $inf) = $this->_redis->hMGet(self::PREFIX_KEY.$id, array(self::FIELD_TAGS, self::FIELD_MTIME, self::FIELD_INF));
        if( ! $mtime) {
          return FALSE;
        }
        $tags = explode(',', $this->_decodeData($tags));
        $expire = $inf === '1' ? FALSE : time() + $this->_redis->ttl(self::PREFIX_KEY.$id);

        return array(
            'expire' => $expire,
            'tags'   => $tags,
            'mtime'  => $mtime,
        );
    }

    /**
     * Give (if possible) an extra lifetime to the given cache id
     *
     * @param string $id cache id
     * @param int $extraLifetime
     * @return boolean true if ok
     */
    public function touch($id, $extraLifetime)
    {
        list($inf) = $this->_redis->hGet(self::PREFIX_KEY.$id, self::FIELD_INF);
        if ($inf === '0') {
            $expireAt = time() + $this->_redis->ttl(self::PREFIX_KEY.$id) + $extraLifetime;
            return (bool) $this->_redis->expireAt(self::PREFIX_KEY.$id, $expireAt);
        }
        return false;
    }

    /**
     * Return an associative array of capabilities (booleans) of the backend
     *
     * The array must include these keys :
     * - automatic_cleaning (is automating cleaning necessary)
     * - tags (are tags supported)
     * - expired_read (is it possible to read expired cache records
     *                 (for doNotTestCacheValidity option for example))
     * - priority does the backend deal with priority when saving
     * - infinite_lifetime (is infinite lifetime can work with this backend)
     * - get_list (is it possible to get the list of cache ids and the complete list of tags)
     *
     * @return array associative of with capabilities
     */
    public function getCapabilities()
    {
        return array(
            'automatic_cleaning' => ($this->_options['automatic_cleaning_factor'] > 0),
            'tags'               => true,
            'expired_read'       => false,
            'priority'           => false,
            'infinite_lifetime'  => true,
            'get_list'           => true,
            'lock'               => true,
        );
    }

    /**
     * @param string $data
     * @param int $level
     * @throws CredisException
     * @return string
     */
    protected function _encodeData($data, $level)
    {
        if ($level && strlen($data) >= $this->_compressThreshold) {
            switch($this->_compressionLib) {
              case 'snappy': $data = snappy_compress($data); break;
              case 'lzf':    $data = lzf_compress($data); break;
              case 'gzip':   $data = gzcompress($data, $level); break;
            }
            if( ! $data) {
                throw new CredisException("Could not compress cache data.");
            }
            return $this->_compressPrefix.$data;
        }
        return $data;
    }

    /**
     * @param bool|string $data
     * @return string
     */
    protected function _decodeData($data)
    {
        if (substr($data,2,3) == self::COMPRESS_PREFIX) {
            switch(substr($data,0,2)) {
                case 'sn': return snappy_uncompress(substr($data,5));
                case 'lz': return lzf_decompress(substr($data,5));
                case 'gz': case 'zc': return gzuncompress(substr($data,5));
            }
        }
        return $data;
    }

    /**
     * @param $item
     * @param $index
     * @param $prefix
     */
    protected function _preprocess(&$item, $index, $prefix)
    {
        $item = $prefix . $item;
    }

    /**
     * @param $ids
     * @return array
     */
    protected function _preprocessIds($ids)
    {
        array_walk($ids, array($this, '_preprocess'), self::PREFIX_KEY);
        return $ids;
    }

    /**
     * @param $tags
     * @return array
     */
    protected function _preprocessTagIds($tags)
    {
        array_walk($tags, array($this, '_preprocess'), self::PREFIX_TAG_IDS);
        return $tags;
    }

    /**
     * Required to pass unit tests
     *
     * @param  string $id
     * @return void
     */
    public function ___expire($id)
    {
        $this->_redis->del(self::PREFIX_KEY.$id);
    }
    public function getRedis(){
        return $this->_redis;
    }



}