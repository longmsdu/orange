<?php

namespace Orange\Async;

use Orange\Async\Client\File;
use Orange\Application\Code;

class AsyncFile
{   
    /**
     * 异步读取 文件大小必须小于4M
     */
    public static function read($filename)
    {
        $file = new File();
        $file->read($filename);
        $res = (yield $file);

        yield $res;
    }

    /**
     * 异步写入 文件大小必须小于4M
     */
    public static function write($filename, $content, $flags = 0)
    {   
        self::checkWritePermission($filename);

        $file = new File();
        $file->write($filename, $content, $flags);
        $res = (yield $file);

        yield $res;
    }

    private static function checkWritePermission($filename)
    {
        $dir = dirname($filename);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (file_exists($filename) && !is_writable($filename)) {
            $e = new \Exception("The {$filename} not writable!", Code::FILE_NO_ACCESS);
            yield throwException($e);
        }
    }
}
