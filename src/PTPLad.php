<?php

namespace Garkavenkov\PTPLad;

class PTPLad
{
    /**
     * Path to the folder for extraction dump
     * @var String
     */
    private static $dump_dest;

    /**
     * Extracts archive
     *
     * Extracts archive from $source  into $dest folder
     *
     * @param  string  $source Path to archive file
     * @param  string  $dest   Path to the folder for extraction
     * @param  boolean $log    Output work results
     * @return boolean         Work result
     */
    public static function extractArchive(string $source, string $dest, $log=false)
    {
        // Chech whether 'zip' module is loaded or not
        if (!extension_loaded('zip')) {
            $versions = explode('.', phpversion());
            echo "Zip module for PHP is not loaded." . PHP_EOL;
            echo "You need to install package 'php$versions[0].$versions[1]-zip' for continue to work." . PHP_EOL;
            exit();
        }

        // Extract archive into '$dest' folder
        if (file_exists($source)) {
            if (!isset($dest) && !is_dir($dest)) {
                echo "Destination is not a folder. Exit..." . PHP_EOL;
                exit();
            } else {
                self::$dump_dest = $dest;
            }

            $zip = new \ZipArchive();
            if ($zip->open($source) === true) {
                $zip->extractTo($dest);
                $zip->close();
                if ($log) {
                    echo "Архив распакован в папку '$dest'..." . PHP_EOL;
                }
                return true;
            } else {
                echo "Something went wrong. I cannot initialize Zip object.";
                return false;
            }
        } else {
            echo "Archive '$source' not found!." . PHP_EOL;
            exit();
        }
    }
}
