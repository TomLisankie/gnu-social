<?php

/*

Subscription flow:

    $feedinfo->subscribe()
        generate random verification token
            save to verify_token
        sends a sub request to the hub...
    
    feedsub/callback
        hub sends confirmation back to us via GET
        We verify the request, then echo back the challenge.
        On our end, we save the time we subscribed and the lease expiration
    
    feedsub/callback
        hub sends us updates via POST
        ?
    
*/

class FeedDBException extends FeedSubException
{
    public $obj;

    function __construct($obj)
    {
        parent::__construct('Database insert failure');
        $this->obj = $obj;
    }
}

class Feedinfo extends Memcached_DataObject
{
    public $__table = 'feedinfo';

    public $id;
    public $profile_id;

    public $feeduri;
    public $homeuri;
    public $huburi;

    // PuSH subscription data
    public $verify_token;
    public $sub_start;
    public $sub_end;

    public $created;
    public $lastupdate;


    public /*static*/ function staticGet($k, $v=null)
    {
        return parent::staticGet(__CLASS__, $k, $v);
    }

    /**
     * return table definition for DB_DataObject
     *
     * DB_DataObject needs to know something about the table to manipulate
     * instances. This method provides all the DB_DataObject needs to know.
     *
     * @return array array of column definitions
     */

    function table()
    {
        return array('id' => DB_DATAOBJECT_INT + DB_DATAOBJECT_NOTNULL,
                     'profile_id' => DB_DATAOBJECT_INT + DB_DATAOBJECT_NOTNULL,
                     'feeduri' => DB_DATAOBJECT_STR + DB_DATAOBJECT_NOTNULL,
                     'homeuri' => DB_DATAOBJECT_STR + DB_DATAOBJECT_NOTNULL,
                     'huburi' =>  DB_DATAOBJECT_STR + DB_DATAOBJECT_NOTNULL,
                     'verify_token' => DB_DATAOBJECT_STR,
                     'sub_start' => DB_DATAOBJECT_STR + DB_DATAOBJECT_DATE + DB_DATAOBJECT_TIME,
                     'sub_end' => DB_DATAOBJECT_STR + DB_DATAOBJECT_DATE + DB_DATAOBJECT_TIME,
                     'created' => DB_DATAOBJECT_STR + DB_DATAOBJECT_DATE + DB_DATAOBJECT_TIME + DB_DATAOBJECT_NOTNULL,
                     'lastupdate' => DB_DATAOBJECT_STR + DB_DATAOBJECT_DATE + DB_DATAOBJECT_TIME + DB_DATAOBJECT_NOTNULL);
    }
    
    static function schemaDef()
    {
        return array(new ColumnDef('id', 'integer',
                                   /*size*/ null,
                                   /*nullable*/ false,
                                   /*key*/ 'PRI',
                                   /*default*/ '0',
                                   /*extra*/ null,
                                   /*auto_increment*/ true),
                     new ColumnDef('profile_id', 'integer',
                                   null, false),
                     new ColumnDef('feeduri', 'varchar',
                                   255, false, 'UNI'),
                     new ColumnDef('homeuri', 'varchar',
                                   255, false),
                     new ColumnDef('huburi', 'varchar',
                                   255, false),
                     new ColumnDef('verify_token', 'varchar',
                                   32, true),
                     new ColumnDef('sub_start', 'datetime',
                                   null, true),
                     new ColumnDef('sub_end', 'datetime',
                                   null, true),
                     new ColumnDef('created', 'datetime',
                                   null, false),
                     new ColumnDef('lastupdate', 'datetime',
                                   null, false));
    }

    /**
     * return key definitions for DB_DataObject
     *
     * DB_DataObject needs to know about keys that the table has; this function
     * defines them.
     *
     * @return array key definitions
     */

    function keys()
    {
        return array('id' => 'P'); //?
    }

    /**
     * return key definitions for Memcached_DataObject
     *
     * Our caching system uses the same key definitions, but uses a different
     * method to get them.
     *
     * @return array key definitions
     */

    function keyTypes()
    {
        return $this->keys();
    }

    /**
     * Fetch the StatusNet-side profile for this feed
     * @return Profile
     */
    public function getProfile()
    {
        return Profile::staticGet('id', $this->profile_id);
    }

    /**
     * @param FeedMunger $munger
     * @return Feedinfo
     */
    public static function ensureProfile($munger)
    {
        $feedinfo = $munger->feedinfo();

        $current = self::staticGet('feeduri', $feedinfo->feeduri);
        if ($current) {
            // @fixme we should probably update info as necessary
            return $current;
        }

        $feedinfo->query('BEGIN');

        try {
            $profile = $munger->profile();
            $result = $profile->insert();
            if (empty($result)) {
                throw new FeedDBException($profile);
            }

            $feedinfo->profile_id = $profile->id;
            $result = $feedinfo->insert();
            if (empty($result)) {
                throw new FeedDBException($feedinfo);
            }

            $feedinfo->query('COMMIT');
        } catch (FeedDBException $e) {
            common_log_db_error($e->obj, 'INSERT', __FILE__);
            $feedinfo->query('ROLLBACK');
            return false;
        }
        return $feedinfo;
    }

    /**
     * Send a subscription request to the hub for this feed.
     * The hub will later send us a confirmation POST to /feedsub/callback.
     *
     * @return bool true on success, false on failure
     */
    public function subscribe()
    {
        // @fixme use the verification token
        #$token = md5(mt_rand() . ':' . $this->feeduri);
        #$this->verify_token = $token;
        #$this->update(); // @fixme
        
        try {
            $callback = common_local_url('feedsubcallback', array('feed' => $this->id));
            $headers = array('Content-Type: application/x-www-form-urlencoded');
            $post = array('hub.mode' => 'subscribe',
                          'hub.callback' => $callback,
                          'hub.verify' => 'async',
                          //'hub.verify_token' => $token,
                          //'hub.lease_seconds' => 0,
                          'hub.topic' => $this->feeduri);
            $client = new HTTPClient();
            $response = $client->post($this->huburi, $headers, $post);
            if ($response->getStatus() >= 200 && $response->getStatus() < 300) {
                common_log(LOG_INFO, __METHOD__ . ': sub req ok');
                return true;
            } else {
                common_log(LOG_INFO, __METHOD__ . ': sub req failed');
                return false;
            }
        } catch (Exception $e) {
            // wtf!
            common_log(LOG_ERR, __METHOD__ . ": error \"{$e->getMessage()}\" hitting hub $this->huburi subscribing to $this->feeduri");
            return false;
        }
    }

    /**
     * Read and post notices for updates from the feed.
     * Currently assumes that all items in the feed are new,
     * coming from a PuSH hub.
     *
     * @param string $xml source of Atom or RSS feed
     */
    public function postUpdates($xml)
    {
        common_log(LOG_INFO, __METHOD__ . ": packet for \"$this->feeduri\"! $xml");
        require_once "XML/Feed/Parser.php";
        $feed = new XML_Feed_Parser($xml, false, false, true);
        $munger = new FeedMunger($feed);
        
        $hits = 0;
        foreach ($feed as $index => $entry) {
            // @fixme this might sort in wrong order if we get multiple updates
            
            $notice = $munger->notice($index);
            $notice->profile_id = $this->profile_id;
            
            // Double-check for oldies
            // @fixme this could explode horribly for multiple feeds on a blog. sigh
            $dupe = new Notice();
            $dupe->uri = $notice->uri;
            $dupe->find();
            if ($dupe->fetch()) {
                common_log(LOG_WARNING, __METHOD__ . ": tried to save dupe notice for entry {$notice->uri} of feed {$this->feeduri}");
                continue;
            }
            
            if (Event::handle('StartNoticeSave', array(&$notice))) {
                $id = $notice->insert();
                Event::handle('EndNoticeSave', array($notice));
            }
            $notice->addToInboxes();

            common_log(LOG_INFO, __METHOD__ . ": saved notice {$notice->id} for entry $index of update to \"{$this->feeduri}\"");
            $hits++;
        }
        if ($hits == 0) {
            common_log(LOG_INFO, __METHOD__ . ": no updates in packet for \"$this->feeduri\"! $xml");
        }
    }
}
