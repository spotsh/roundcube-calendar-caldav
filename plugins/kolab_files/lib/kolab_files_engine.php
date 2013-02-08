<?php

/**
 * Kolab files storage engine
 *
 * @version @package_version@
 * @author Aleksander Machniak <machniak@kolabsys.com>
 *
 * Copyright (C) 2013, Kolab Systems AG <contact@kolabsys.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

class kolab_files_engine
{
    private $plugin;
    private $rc;
    private $timeout = 60;

    /**
     * Class constructor
     */
    public function __construct($plugin, $url)
    {
        $this->url    = $url;
        $this->plugin = $plugin;
        $this->rc     = $plugin->rc;
    }

    /**
     * User interface initialization
     */
    public function ui()
    {
        $this->plugin->add_texts('localization/', true);
        $this->rc->output->set_env('files_url', $this->url . '/api/');
        $this->rc->output->set_env('files_token', $this->get_api_token());

        $this->plugin->include_stylesheet($this->plugin->local_skin_path().'/style.css');
        $this->plugin->include_stylesheet($this->url . '/skins/default/images/mimetypes/style.css');
        $this->plugin->include_script($this->url . '/js/files_api.js');
        $this->plugin->include_script('kolab_files.js');
    }

    /**
     * Engine actions handler
     */
    public function actions()
    {
        $action = $_POST['act'];
        $method = 'action_' . $action;

        if (method_exists($this, $method)) {
            $this->plugin->add_texts('localization/');
            $this->{$method}();
        }
    }

    /**
     * Get API token for current user session, authenticate if needed
     */
    protected function get_api_token()
    {
        $token = $_SESSION['kolab_files_token'];
        $time  = $_SESSION['kolab_files_time'];

        if ($token && time() - $this->timeout < $time) {
            return $token;
        }

        if (!($request = $this->get_request())) {
            return $token;
        }

        try {
            $url = $request->getUrl();

            // Send ping request
            if ($token) {
                $url->setQueryVariables(array('method' => 'ping'));
                $request->setUrl($url);
                $response = $request->send();
                $status   = $response->getStatus();

                if ($status == 200 && ($body = json_decode($response->getBody(), true))) {
                    if ($body['status'] == 'OK') {
                        $_SESSION['kolab_files_time']  = time();
                        return $token;
                    }
                }
            }

            // Go with authenticate request
            $url->setQueryVariables(array('method' => 'authenticate'));
            $request->setUrl($url);
            $request->setAuth($this->rc->user->get_username(), $this->rc->decrypt($_SESSION['password']));
            $response = $request->send();
            $status   = $response->getStatus();

            if ($status == 200 && ($body = json_decode($response->getBody(), true))) {
                $token = $body['result']['token'];

                if ($token) {
                    $_SESSION['kolab_files_token'] = $token;
                    $_SESSION['kolab_files_time']  = time();
                }
            }
            else {
                throw new Exception(sprintf("Authenticate error (Status: %d)", $status));
            }
        }
        catch (Exception $e) {
            rcube::raise_error($e, true, false);
        }

        return $token;
    }

    /**
     * Initialize HTTP_Request object
     */
    protected function get_request()
    {
        $url = $this->url . '/api/';

        if (!$this->request) {
            require_once 'HTTP/Request2.php';

            try {
                $request = new HTTP_Request2();
                $request->setConfig(array(
                    'store_body'       => true,
                    'follow_redirects' => true,
                    'ssl_verify_peer'  => $this->rc->config->get('kolab_ssl_verify_peer', true),
                ));

                $this->request = $request;
            }
            catch (Exception $e) {
                rcube::raise_error($e, true, true);
            }
        }

        if ($this->request) {
            // cleanup
            try {
                $this->request->setBody('');
                $this->request->setUrl($url);
                $this->request->setMethod(HTTP_Request2::METHOD_GET);
            }
            catch (Exception $e) {
                rcube::raise_error($e, true, true);
            }
        }

        return $this->request;
    }

    /**
     * Handler for "save all attachments into cloud" action
     */
    protected function action_saveall()
    {
        $source = rcube_utils::get_input_value('source', rcube_utils::INPUT_POST);
        $uid    = rcube_utils::get_input_value('uid', rcube_utils::INPUT_POST);
        $dest   = rcube_utils::get_input_value('dest', rcube_utils::INPUT_POST);

        $temp_dir = unslashify($this->rc->config->get('temp_dir'));
        $storage  = $this->rc->get_storage();
        $message  = new rcube_message($uid);
        $request  = $this->get_request();
        $url      = $request->getUrl();
        $files    = array();
        $errors   = array();

        $request->setMethod(HTTP_Request2::METHOD_POST);
        $request->setHeader('X-Session-Token', $this->get_api_token());
        $url->setQueryVariables(array('method' => 'file_create', 'folder' => $dest));
        $request->setUrl($url);

        // @TODO: handle error
        // @TODO: implement file upload using file URI instead of body upload

        foreach ($message->attachments as $attach_prop) {
            $filename = rcmail_attachment_name($attach_prop, true);
            $path     = tempnam($temp_dir, 'rcmAttmnt');

            // save attachment to file
            if ($fp = fopen($path, 'w+')) {
                $message->get_part_content($attach_prop->mime_id, $fp, true);
            }
            else {
                $errors[] = true;
                rcube::raise_error(array(
                    'code' => 500, 'type' => 'php', 'line' => __LINE__, 'file' => __FILE__,
                    'message' => "Unable to save attachment into file $path"),
                    true, false);
                continue;
            }

            fclose($fp);

            // send request to the API
            try {
                $request->setBody('');
                $request->addUpload('file[]', $path, $filename, $attach_prop->mimetype);
                $response = $request->send();
                $status   = $response->getStatus();
                $body     = @json_decode($response->getBody(), true);

                if ($status == 200 && $body['status'] == 'OK') {
                    $files[] = $filename;
                }
                else {
                    throw new Exception($body['reason']);
                }
            }
            catch (Exception $e) {
                unlink($path);
                $errors[] = $e->getMessage();
                rcube::raise_error(array(
                    'code' => 500, 'type' => 'php', 'line' => __LINE__, 'file' => __FILE__,
                    'message' => $e->getMessage()),
                    true, false);
                continue;
            }

            // clean up
            unlink($path);
            $request->setBody('');
        }

        if ($count = count($files)) {
            $this->rc->output->show_message($this->plugin->gettext('saveallnotice', array('n' => $count)), 'confirmation');
        }
        if ($count = count($errors)) {
            $this->rc->output->show_message($this->plugin->gettext('saveallerror', array('n' => $count)), 'error');
        }

        $this->rc->output->send();
    }

    /**
     * Handler for "add attachments from the cloud" action
     */
    protected function action_attach()
    {
        $folder     = rcube_utils::get_input_value('folder', rcube_utils::INPUT_POST);
        $files      = rcube_utils::get_input_value('files', rcube_utils::INPUT_POST);
        $uploadid   = rcube_utils::get_input_value('uploadid', rcube_utils::INPUT_POST);
        $COMPOSE_ID = rcube_utils::get_input_value('id', rcube_utils::INPUT_POST);
        $COMPOSE    = null;
        $errors     = array();

        if ($COMPOSE_ID && $_SESSION['compose_data_'.$COMPOSE_ID]) {
            $COMPOSE =& $_SESSION['compose_data_'.$COMPOSE_ID];
        }

        if (!$COMPOSE) {
            die("Invalid session var!");
        }

        // attachment upload action
        if (!is_array($COMPOSE['attachments'])) {
            $COMPOSE['attachments'] = array();
        }

        // clear all stored output properties (like scripts and env vars)
        $this->rc->output->reset();

        $temp_dir = unslashify($this->rc->config->get('temp_dir'));
        $request  = $this->get_request();
        $url      = $request->getUrl();

        // Use observer object to store HTTP response into a file
        require_once $this->plugin->home . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'kolab_files_observer.php';
        $observer = new kolab_files_observer();

        $request->setHeader('X-Session-Token', $this->get_api_token());

        // download files from the API and attach them
        foreach ($files as $file) {
            // decode filename
            $file = urldecode($file);

            // get file information
            try {
                $url->setQueryVariables(array('method' => 'file_info', 'folder' => $folder, 'file' => $file));
                $request->setUrl($url);
                $response = $request->send();
                $status   = $response->getStatus();
                $body     = @json_decode($response->getBody(), true);

                if ($status == 200 && $body['status'] == 'OK') {
                    $file_params = $body['result'];
                }
                else {
                    throw new Exception($body['reason']);
                }
            }
            catch (Exception $e) {
                $errors[] = $e->getMessage();
                rcube::raise_error(array(
                    'code' => 500, 'type' => 'php', 'line' => __LINE__, 'file' => __FILE__,
                    'message' => $e->getMessage()),
                    true, false);
                continue;
            }

            // set location of downloaded file
            $path = tempnam($temp_dir, 'rcmAttmnt');
            $observer->set_file($path);

            // download file
            try {
                $url->setQueryVariables(array('method' => 'file_get', 'folder' => $folder, 'file' => $file));
                $request->setUrl($url);
                $request->attach($observer);
                $response = $request->send();
                $status   = $response->getStatus();
                $response->getBody(); // returns nothing
                $request->detach($observer);

                if ($status != 200 || !file_exists($path)) {
                    throw new Exception("Unable to save file");
                }
            }
            catch (Exception $e) {
                $errors[] = $e->getMessage();
                rcube::raise_error(array(
                    'code' => 500, 'type' => 'php', 'line' => __LINE__, 'file' => __FILE__,
                    'message' => $e->getMessage()),
                    true, false);
                continue;
            }

            $attachment = array(
                'path' => $path,
                'size' => $file_params['size'],
                'name' => $file_params['name'],
                'mimetype' => $file_params['type'],
                'group' => $COMPOSE_ID,
            );

            $attachment = $this->rc->plugins->exec_hook('attachment_save', $attachment);

            if ($attachment['status'] && !$attachment['abort']) {
                $id = $attachment['id'];

                // store new attachment in session
                unset($attachment['status'], $attachment['abort']);
                $COMPOSE['attachments'][$id] = $attachment;

                if (($icon = $COMPOSE['deleteicon']) && is_file($icon)) {
                    $button = html::img(array(
                        'src' => $icon,
                        'alt' => $this->rc->gettext('delete')
                    ));
                }
                else {
                    $button = Q($this->rc->gettext('delete'));
                }

                $content = html::a(array(
                    'href' => "#delete",
                    'onclick' => sprintf("return %s.command('remove-attachment','rcmfile%s', this)", JS_OBJECT_NAME, $id),
                    'title' => $this->rc->gettext('delete'),
                    'class' => 'delete',
                ), $button);

                $content .= Q($attachment['name']);

                $this->rc->output->command('add2attachment_list', "rcmfile$id", array(
                    'html'      => $content,
                    'name'      => $attachment['name'],
                    'mimetype'  => $attachment['mimetype'],
                    'classname' => rcmail_filetype2classname($attachment['mimetype'], $attachment['name']),
                    'complete'  => true), $uploadid);
            }
            else if ($attachment['error']) {
                $errors[] = $attachment['error'];
            }
            else {
                $errors[] = $this->rc->gettext('fileuploaderror');
            }
        }

        if (!empty($errors)) {
            $this->rc->output->command('display_message', "Failed to attach file(s) from cloud", 'error');
            $this->rc->output->command('remove_from_attachment_list', $uploadid);
        }

        // send html page with JS calls as response
        $this->rc->output->command('auto_save_start', false);
        $this->rc->output->send();
    }
}
