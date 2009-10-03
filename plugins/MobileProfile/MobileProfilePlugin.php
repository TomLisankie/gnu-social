<?php
/**
 * StatusNet, the distributed open-source microblogging tool
 *
 * XHTML Mobile Profile plugin that uses WAP 2.0 Plugin
 *
 * PHP version 5
 *
 * LICENCE: This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @category  Plugin
 * @package   StatusNet
 * @author    Sarven Capadisli <csarven@status.net>
 * @copyright 2009 StatusNet, Inc.
 * @license   http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link      http://status.net/
 */

if (!defined('STATUSNET') && !defined('LACONICA')) {
    exit(1);
}

define('PAGE_TYPE_PREFS',
       'application/vnd.wap.xhtml+xml, application/xhtml+xml, text/html;q=0.9');

require_once INSTALLDIR.'/plugins/Mobile/WAP20Plugin.php';


/**
 * Superclass for plugin to output XHTML Mobile Profile
 *
 * @category Plugin
 * @package  StatusNet
 * @author   Sarven Capadisli <csarven@status.net>
 * @license  http://www.fsf.org/licensing/licenses/agpl-3.0.html GNU Affero General Public License version 3.0
 * @link     http://status.net/
 */

class MobileProfilePlugin extends WAP20Plugin
{
    public $DTDversion      = null;
    public $serveMobile     = false;

    function __construct($DTD='http://www.wapforum.org/DTD/xhtml-mobile10.dtd')
    {
        $this->DTD       = $DTD;

        parent::__construct();
    }


    function onStartShowHTML($action)
    {
        if (!$type) {
            $httpaccept = isset($_SERVER['HTTP_ACCEPT']) ?
              $_SERVER['HTTP_ACCEPT'] : null;

            $cp = common_accept_to_prefs($httpaccept);
            $sp = common_accept_to_prefs(PAGE_TYPE_PREFS);

            $type = common_negotiate_type($cp, $sp);

            if (!$type) {
                throw new ClientException(_('This page is not available in a '.
                                            'media type you accept'), 406);
            }
        }

        // XXX: This should probably graduate to WAP20Plugin

        // If they are on the mobile site, serve them MP
        if ((common_config('site', 'mobileserver').'/'.
             common_config('site', 'path').'/' == 
            $_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'])) {

            $this->serveMobile = true;
        }
        else {
            // If they like the WAP 2.0 mimetype, serve them MP
            if (strstr('application/vnd.wap.xhtml+xml', $type) !== false) {
                $this->serveMobile = true;
            }
            else {
                // If they are a mobile device that supports WAP 2.0, 
                // serve them MP

                // XXX: Browser sniffing sucks

                // I really don't like going through this every page, 
                // find a better way

                // May be better to categorize the devices in terms of 
                // low,mid,high-end

                // Or, detect the mobile devices based on their support for 
                // MP 1.0, 1.1, or 1.2 may be ideal. Possible?

                $this->mobiledevices = 
                    array('alcatel', 'android', 'audiovox', 'au-mic,', 
                          'avantgo', 'blackberry', 'blazer', 'cldc-', 'danger', 
                          'epoc', 'ericsson', 'ericy', 'ipone', 'ipaq', 'j2me', 
                          'lg', 'midp-', 'mobile', 'mot', 'netfront', 'nitro', 
                          'nokia', 'opera mini', 'palm', 'palmsource', 
                          'panasonic', 'philips', 'pocketpc', 'portalmmm', 
                          'rover', 'samsung', 'sanyo', 'series60', 'sharp', 
                          'sie-', 'smartphone', 'sony', 'symbian', 
                          'up.browser', 'up.link', 'up.link', 'vodafone', 
                          'wap1', 'wap2', 'windows ce');

                $httpuseragent = strtolower($_SERVER['HTTP_USER_AGENT']);

                foreach($this->mobiledevices as $md) {
                    if (strstr($httpuseragent, $md) !== false) {
                        $this->serveMobile = true;
                        break;
                    }
                }
            }

            // If they are okay with MP, and the site has a mobile server, 
            // redirect there
            if ($this->serveMobile && 
                common_config('site', 'mobileserver') !== false) {

                header("Location: ".common_config('site', 'mobileserver'));
                exit();
            }
        }

        header('Content-Type: '.$type);

        $action->extraHeaders();

        $action->startXML('html',
                        '-//WAPFORUM//DTD XHTML Mobile 1.0//EN',
                        $this->DTD);

        $language = $action->getLanguage();

        $action->elementStart('html', array('xmlns' => 'http://www.w3.org/1999/xhtml',
                                            'xml:lang' => $language));

        return false;
    }



    function onStartShowAside($action)
    {

    }


    function onStartShowScripts($action)
    {

    }

}


?>
