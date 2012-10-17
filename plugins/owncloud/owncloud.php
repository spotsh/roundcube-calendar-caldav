<?php

/**
 * OwnCloud Plugin
 *
 * @author Aleksander 'A.L.E.C' Machniak <machniak@kolabsys.com>
 * @licence GNU AGPL
 *
 * Configuration (see config.inc.php.dist)
 * 
 */

class owncloud extends rcube_plugin
{
    // all task excluding 'login' and 'logout'
    public $task = '?(?!login|logout).*';
    // we've got no ajax handlers
    public $noajax = true;
    // skip frames
    public $noframe = true;

    function init()
    {
        $rcmail = rcube::get_instance();

        // requires kolab_auth plugin
        if (empty($_SESSION['kolab_uid'])) {
            return;
        }

        $this->add_texts('localization/', false);

        // register task
        $this->register_task('owncloud');

        // register actions
        $this->register_action('index', array($this, 'action'));
        $this->register_action('redirect', array($this, 'redirect'));

        // add taskbar button
        $this->add_button(array(
            'command'    => 'owncloud',
            'class'      => 'button-owncloud',
            'classsel'   => 'button-owncloud button-selected',
            'innerclass' => 'button-inner',
            'label'      => 'owncloud.owncloud',
            ), 'taskbar');

        // add style for taskbar button (must be here) and Help UI
        $this->include_stylesheet($this->local_skin_path()."/owncloud.css");
    }

    function action()
    {
        $rcmail = rcube::get_instance();

        $rcmail->output->add_handlers(array('owncloudframe' => array($this, 'frame')));
        $rcmail->output->set_pagetitle($this->gettext('owncloud'));
        $rcmail->output->send('owncloud.owncloud');
    }

    function frame()
    {
        $rcmail = rcube::get_instance();

        $this->load_config();

        $src  = $rcmail->config->get('owncloud_url');
        $user = $_SESSION['kolab_uid']; // requires kolab_auth plugin
        $pass = $rcmail->decrypt($_SESSION['password']);

        $src = preg_replace('/^(https?:\/\/)/',
            '\\1' . urlencode($user) . ':' . urlencode($pass) . '@', $src);

        return '<iframe id="owncloudframe" width="100%" height="100%" frameborder="0"'
            .' src="' . $src. '"></iframe>';
    }

}
