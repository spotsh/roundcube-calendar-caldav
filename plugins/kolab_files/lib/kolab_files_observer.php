<?php

/**
 * Observer for HTTP_Request2 implementing saving response body into a file
 */
class kolab_files_observer implements SplObserver
{
    protected $file;
    protected $fp;

    public function set_file($file)
    {
        $this->file = $file;
    }

    public function update(SplSubject $subject)
    {
        $event = $subject->getLastEvent();

        switch ($event['name']) {
        case 'receivedHeaders':
            $target = $this->dir . DIRECTORY_SEPARATOR . $this->file;
            if (!($this->fp = @fopen($target, 'wb'))) {
                throw new Exception("Cannot open target file '{$target}'");
            }
            break;

        case 'receivedBodyPart':
        case 'receivedEncodedBodyPart':
            fwrite($this->fp, $event['data']);
            break;

        case 'receivedBody':
            fclose($this->fp);
            break;
        }
    }
}
