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
     * Extracts archive from '$source' file  into '$dest' folder
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

    /**
     * Import categories from PTPLad database dump file
     * into table 'ptplad_category'
     *
     * @param  boolean $log Output information
     * @return boolean      Work result
     */
    public static function importCategories($log = false)
    {
        // Destination folder not found
        if (!self::$dest) {
            echo "Cannot find folder with exracted files." . PHP_EOL;
            exit;
        }

        // Make path to the file
        $dir = rtrim($this->dest, '/') .'/webdata/000000001/import___*';
        // Grab first file from an array
        $file = glob($dir)[0];
        if (!$file) {
            echo "Cannot find file with categories in destination folder." . PHP_EOL;
            exit;
        }

        // Load XML from a file
        $dom = \DOMDocument::load($file);
        // Create DOMXPath object
        $xpath = new \DOMXPath($dom);

        // Register prefix
        $prefix = "ptplad";
        $rootNamespace = $dom->lookupNamespaceUri($dom->namespaceURI);
        if (!($xpath->registerNamespace($prefix, $rootNamespace))) {
            echo "Cannot register namespace {$uri}.";
        };

        // Category depth
        $depth=0;

        $catalog = [];

        // XPath query
        $main_xquery  = "/${prefix}:КоммерческаяИнформация";
        $main_xquery .= "/${prefix}:Классификатор";
        $main_xquery .= "/${prefix}:Группы";
        $main_xquery .= "/${prefix}:Группа";

        // Main category
        $group = $xpath->query($main_xquery);
        $id = $group->item(0)->getElementsByTagName('Ид')[0]->textContent;
        $name =  $group->item(0)->getElementsByTagName('Наименование')[0]->textContent;

        // Categories array
        $catalog[] = array(
            'parent_id' => 0,
            'id'        => $id,
            'name'      => $name,
            'depth'     => $depth
        );

        // Search subcategories
        $xquery_part = "/${prefix}:Группы/${prefix}:Группа";
        $i=1;
        $cycle = true;
        while ($cycle) {
            $xquery = '';
            // Categories
            $xquery = $main_xquery . str_repeat($xquery_part, $i);

            $groups = $xpath->query($xquery);

            if ($groups->length > 0) {
                $depth++;
            } else {
                $cycle = false;
            }
            foreach ($groups as $group) {
                $id = $group->getElementsByTagName('Ид')[0]->textContent;
                $name =  $group->getElementsByTagName('Наименование')[0]->textContent;
                $parent_id = $group->parentNode->parentNode->getElementsByTagName('Ид')[0]->textContent . PHP_EOL;
                $catalog[] = array(
                    'parent_id' => $parent_id,
                    'id'  => $id,
                    'name' => $name,
                    'depth' => $depth
                );
            }
            $i++;
        }

        // Create table `ptplad_category`
        $sql  = "DROP TABLE IF EXISTS `ptplad_category`; ";
        $sql .= "CREATE TABLE `ptplad_category` (";
        $sql .=     "`id` varchar(36) NOT NULL,";
        $sql .=     "`parent_id` varchar(36) NOT NULL,";
        $sql .=     "`name` varchar(255) NOT NULL,";
        $sql .=     "`depth` tinyint(4) NOT NULL,";
        $sql .=     "PRIMARY KEY (`id`)";
        $sql .= ") ENGINE=InnoDB DEFAULT CHARSET=utf8;";
        if (!self::$dbh->query($sql)) {
            echo "Cannot create table `ptplad_category`... Exit." . PHP_EOL;
            exit();
        }

        // Prepared SQL statement for `ptplad_category`
        $sql  = "INSERT INTO `ptplad_category` (";
        $sql .=     "`id`, ";
        $sql .=     "`parent_id`, ";
        $sql .=     "`name`, ";
        $sql .=     "`depth`";
        $sql .= ") VALUES (";
        $sql .=     ":id, ";
        $sql .=     ":parent_id, ";
        $sql .=     ":name, ";
        $sql .=     ":depth";
        $sql .= ")";
        $stmt = self::$dbh->prepare($sql);
        if (!$stmt) {
            echo "Cannot create prepares SQL statement for table 'ptplad_category'. Exit..." . PHP_EOL;
            exit();
        }

        // Insert categories into the table `ptplad_category`
        $categories = 0;
        foreach ($catalog as $entry) {
            $stmt->execute(array(
                ":id" => $entry['id'],
                ":parent_id" => $entry['parent_id'],
                ":name" => $entry['name'],
                ":depth" => $entry['depth']
            ));
            $categories++;
        }
        if ($log) {
            echo "Импортировано $categories категорий...." . PHP_EOL;
        }
        return true;
    }

    /**
     * Imports Price type
     * @param  boolean $log Output information
     * @return boolean      Work result
     */
    public static function importPriceType($log = false)
    {
        // Destination folder not found
        if (!self::$dest) {
            echo "Cannot find folder with exracted files." . PHP_EOL;
            exit;
        }

        // Make path to the file
        $dir = rtrim(self::$dest, '/') .'/webdata/000000001/import___*';
        // Grab first file from an array
        $file = glob($dir)[0];
        if (!$file) {
            echo "Cannot find file with categories in destination folder." . PHP_EOL;
            exit;
        }

        $start = microtime(true);

        // Load XML from a file
        $dom = \DOMDocument::load($file);
        // Create DOMXPath object
        $xpath = new \DOMXPath($dom);

        // Register prefix
        $prefix = "ptplad";
        $rootNamespace = $dom->lookupNamespaceUri($dom->namespaceURI);
        if (!($xpath->registerNamespace($prefix, $rootNamespace))) {
            echo "Cannot register namespace {$uri}.";
        };

        // XPath query
        $xquery  = "/{$prefix}:КоммерческаяИнформация";
        $xquery .= "/{$prefix}:Классификатор";
        $xquery .= "/{$prefix}:ТипыЦен";
        $xquery .= "/{$prefix}:ТипЦены";

        $price_types = $xpath->query($xquery);

        // Crate table `ptplad_price_type`
        $sql  = "DROP TABLE IF EXISTS `ptplad_price_type`; ";
        $sql .= "CREATE TABLE `ptplad_price_type` (";
        $sql .=     "`id` varchar(36) NOT NULL,";
        $sql .=     "`name` varchar(255) NOT NULL,";
        $sql .=     "PRIMARY KEY (`id`)";
        $sql .= ") ENGINE=InnoDB DEFAULT CHARSET=utf8;";
        if (!self::$dbh->query($sql)) {
            echo "Cannot create table `ptplad_price_type`... Exit." . PHP_EOL;
            exit;
        }

        // Prepared SQL statement for table `ptplad_price_type`
        $sql  = "INSERT INTO `ptplad_price_type` (";
        $sql .=     "`id`, ";
        $sql .=     "`name`";
        $sql .= ") VALUES (";
        $sql .=     ":price_type_id,";
        $sql .=     ":price_type_name";
        $sql .= ")";
        $stmt = self::$dbh->prepare($sql);
        if (!$stmt) {
            echo "Cannot create table `ptplad_price_type`... Exit." . PHP_EOL;
            exit();
        }

        $price_type_count = 0;
        if ($price_types->length > 0) {
            foreach ($price_types as $type) {
                $price_type_id = $type->getElementsByTagName('Ид')[0]->textContent;
                $price_type_name = $type->getElementsByTagName('Наименование')[0]->textContent;

                $stmt->execute(array(
                    ":price_type_id" => $price_type_id,
                    ":price_type_name" => $price_type_name
                ));
                $price_type_count++;
            }
        }
        $end = microtime(true);
        $parse_time = $end-$start;
        if ($log) {
            echo "Обработано $price_type_count типов цен за $parse_time." . PHP_EOL;
        }
        return true;
    }

    /**
     * Imports Measure type
     * @param  boolean $log Output information
     * @return boolean      Work result
     */
    public static function importMeasureType($log = false)
    {
        // Destination folder not found
        if (!self::$dest) {
            echo "Cannot find folder with exracted files." . PHP_EOL;
            exit;
        }

        // Makes path to the file
        $dir = rtrim(self::$dest, '/') .'/webdata/000000001/import___*';
        // Grab first file from an array
        $file = glob($dir)[0];
        if (!$file) {
            echo "Cannot find file with categories in destination folder." . PHP_EOL;
            exit;
        }

        $start = microtime(true);

        // Load XML from a file
        $dom = \DOMDocument::load($file);
        // Create DOMXPath object
        $xpath = new \DOMXPath($dom);

        // Register prefix
        $prefix = "ptplad";
        $rootNamespace = $dom->lookupNamespaceUri($dom->namespaceURI);
        if (!($xpath->registerNamespace($prefix, $rootNamespace))) {
            echo "Cannot register namespace {$uri}.";
        };

        // XPathe query
        $xquery  = "/{$prefix}:КоммерческаяИнформация";
        $xquery .= "/{$prefix}:Классификатор";
        $xquery .= "/{$prefix}:ЕдиницыИзмерения";
        $xquery .= "/{$prefix}:ЕдиницаИзмерения";

        $measure_types = $xpath->query($xquery);

        // Create table `ptplad_measure_type`
        $sql  = "DROP TABLE IF EXISTS `ptplad_measure_type`; ";
        $sql .= "CREATE TABLE `ptplad_measure_type` (";
        $sql .=   "`id` varchar(36) NOT NULL,";
        $sql .=   "`short_name` varchar(45) NOT NULL,";
        $sql .=   "`full_name` varchar(100) DEFAULT NULL,";
        $sql .=   "PRIMARY KEY (`id`)";
        $sql .= ") ENGINE=InnoDB DEFAULT CHARSET=utf8;";
        if (!self::$dbh->query($sql)) {
            echo "Cannot create table `ptplad_measure_type`... Exit." . PHP_EOL;
            exit;
        }

        // Prepared SQL statement for table `ptplad_measure_type`
        $sql  = "INSERT INTO `ptplad_measure_type` (";
        $sql .=     "`id`,";
        $sql .=     "`short_name`,";
        $sql .=     "`full_name` ";
        $sql .= ") VALUES (";
        $sql .=     ":id,";
        $sql .=     ":short_name, ";
        $sql .=     ":full_name ";
        $sql .= ")";
        $stmt = self::$dbh->prepare($sql);
        if (!$stmt) {
            echo "Cannot create table `ptplad_measure_type`... Exit." . PHP_EOL;
            exit();
        }

        if ($measure_types->length > 0) {
            $measure_type_count = 0;
            foreach ($measure_types as $type) {
                $measure_type_id = $type->getElementsByTagName('Ид')[0]->textContent;
                $measure_type_short_name = $type->getElementsByTagName('НаименованиеКраткое')[0]->textContent;
                $measure_type_full_name = $type->getElementsByTagName('НаименованиеПолное')[0]->textContent;

                $stmt->execute(array(
                    ":id" => $measure_type_id,
                    ":short_name" => $measure_type_short_name,
                    ":full_name" => $measure_type_full_name
                ));
                $measure_type_count++;
            }
        }
        $end = microtime(true);
        $parse_time = $end-$start;
        if ($log) {
            echo "Обработано $measure_type_count типов едениц измерения за $parse_time." . PHP_EOL;
        }
        return true;
    }

    /**
     * Imports Properties
     * @param  boolean $log Output information
     * @return boolean      Work result
     */
    public static function importProperties($log = false)
    {
        // Parent folder with properties' file
        $main_directory = rtrim(self::$dest, '/') . "/webdata/000000001/properties/";

        // Folders that contain files with properties
        $dirs  = array_diff(scandir($main_directory), array('..', '.'));
        asort($dirs);

        $property_count = 0;
        $arrayProperties = [];
        $arrayPropertiesValues = [];

        // Create table `ptplad_property`
        $sql  = "DROP TABLE IF EXISTS `ptplad_property`; ";
        $sql .= "CREATE TABLE `ptplad_property` (";
        $sql .=     "`id` varchar(36) NOT NULL,";
        $sql .=     "`name` varchar(255) NOT NULL,";
        $sql .=     "PRIMARY KEY (`id`)";
        $sql .= ") ENGINE=InnoDB DEFAULT CHARSET=utf8;";
        if (!self::$dbh->query($sql)) {
            echo "Cannot create table `ptplad_property`... Exit." . PHP_EOL;
            exit;
        }

        // Create table `ptplad_property_value`
        $sql  = "DROP TABLE IF EXISTS `ptplad_property_value`; ";
        $sql .= "CREATE TABLE `ptplad_property_value` (";
        $sql .=     "`property_id` varchar(36) NOT NULL,";
        $sql .=     "`property_value_id` varchar(36) NOT NULL,";
        $sql .=     "`value` varchar(100) NOT NULL,";
        $sql .=     "PRIMARY KEY (`property_id`,`property_value_id`)";
        $sql .= ") ENGINE=InnoDB DEFAULT CHARSET=utf8;";
        if (!self::$dbh->query($sql)) {
            echo "Cannot create table `ptplad_property_value`... Exit." . PHP_EOL;
            exit;
        }

        // Prepared SQL statement for table `ptplad_property_value`
        $sql  = "INSERT INTO `ptplad_property` (";
        $sql .=     "`id`,";
        $sql .=     "`name`";
        $sql .= ") VALUES (";
        $sql .=     ":property_id ,";
        $sql .=     ":property_name";
        $sql .= ")";
        $property_stmt = self::$dbh->prepare($sql);
        if (!$property_stmt) {
            echo "Cannot create table `ptplad_property`... Exit." . PHP_EOL;
            exit();
        }

        // Prepared SQL statement for table `ptplad_property_value`
        $sql  = "INSERT INTO `ptplad_property_value` (";
        $sql .=     "`property_id`, ";
        $sql .=     "`property_value_id`, ";
        $sql .=     "`value`) ";
        $sql .= "VALUES (";
        $sql .=     ":property_id, ";
        $sql .=     ":property_value_id, ";
        $sql .=     ":property_value";
        $sql .= ")";
        $value_stmt = self::$dbh->prepare($sql);
        if (!$value_stmt) {
            echo "Cannot create table `ptplad_property_value`... Exit." . PHP_EOL;
            exit();
        }

        foreach ($dirs as $key => $dir) {
            // Files with properties in folder for array with folders
            $files = array_diff(scandir($main_directory.$dir), array('..', '.'));

            foreach ($files as $key => $file) {
                $filename = $main_directory . $dir . "/" . $file;
                echo "---=== Обрабатывается файл '$filename' ===---" . PHP_EOL;

                // Load XML from a file
                $doc = \DOMDocument::load($filename);
                // Create DOMXPath object
                $xpath = new \DOMXPath($doc);

                // Register prefix
                $prefix = "ptplat";
                $rootNamespace = $doc->lookupNamespaceUri($doc->namespaceURI);
                if (!($xpath->registerNamespace($prefix, $rootNamespace))) {
                    echo "Cannot register namespace {$uri}.";
                };

                // XPath query
                $xquery  = "/{$prefix}:КоммерческаяИнформация";
                $xquery .= "/{$prefix}:Классификатор";
                $xquery .= "/{$prefix}:Свойства";
                $xquery .= "/{$prefix}:Свойство";

                $properties = $xpath->query($xquery);

                foreach ($properties as $property) {
                    $property_id = $property->getElementsByTagName('Ид')[0]->textContent;
                    $property_name = $property->getElementsByTagName('Наименование')[0]->textContent;

                    $property_stmt->execute(array(
                        ":property_id" => $property_id,
                        ":property_name" => $property_name
                    ));

                    $property_values = $property->getElementsByTagName('Справочник');
                    if ($property_values->length > 0) {
                        foreach ($property_values as $value) {
                            $property_value_id = $value->getElementsByTagName('ИдЗначения')[0]->textContent;
                            $property_value = $value->getElementsByTagName('Значение')[0]->textContent;

                            $value_stmt->execute(array(
                                ":property_id"          => $property_id,
                                ":property_value_id"    => $property_value_id,
                                ":property_value"       => $property_value
                            ));
                        }
                    }
                    // echo    "\tДля свойства '$property_name' добавлено " . $property_values->length . " значений" . PHP_EOL;

                    $property_count++;
                }
            }
        }
        if ($log) {
            echo "Обработано $property_count свойств для товаров." . PHP_EOL;
        }
        return true;
    }

    /**
     * Imports Offers
     * @param  boolean $log Output information
     * @return boolean      Work result
     */
    public static function importOffers($log = false)
    {
        $start = microtime(true);
        // Parent folder with offers' file
        $main_directory = rtrim(self::$dest, '/') . "/webdata/000000001/goods/";

        // Folders that contain files with offers
        $dirs  = array_diff(scandir($main_directory), array('..', '.'));
        asort($dirs);

        $product_count = 0;

        // Create table `ptplad_offer`
        $sql  = "DROP TABLE IF EXISTS `ptplad_offer`; ";
        $sql .= "CREATE TABLE `ptplad_offer` (";
        $sql .=   "`product_id` varchar(36) NOT NULL,";
        $sql .=   "`name` varchar(255) NOT NULL,";
        $sql .=   "PRIMARY KEY (`product_id`)";
        $sql .= ") ENGINE=InnoDB DEFAULT CHARSET=utf8;";
        if (!self::$dbh->query($sql)) {
            echo "Cannot create table `ptplad_offer`... Exit." . PHP_EOL;
            exit;
        }

        // Prepared SQL statement for table `ptplad_offer`
        $sql  = "INSERT INTO `ptplad_offer` (";
        $sql .=     "`product_id`,";
        $sql .=     "`name`";
        $sql .= ") VALUES (";
        $sql .=     ":product_id,";
        $sql .=     ":product_name";
        $sql .= ")";
        $offer_stmt = self::$dbh->prepare($sql);
        if (!$offer_stmt) {
            echo "Cannot create table `ptplad_offer`... Exit." . PHP_EOL;
            exit();
        }

        foreach ($dirs as $key => $dir) {
            chdir($main_directory . $dir);

            $dh = opendir($main_directory . $dir);

            if ($dh = opendir($main_directory . $dir)) {
                $files = glob('offers___*');
            };

            foreach ($files as $key => $file) {
                $filename =  $main_directory . $dir . "/" . $file;
                // echo "---=== Обрабатывается файл '$filename' ===---" . PHP_EOL;

                $doc = \DOMDocument::load($filename);
                $xpath = new \DOMXPath($doc);

                $prefix = "ptplad";
                $rootNamespace = $doc->lookupNamespaceUri($doc->namespaceURI);

                if (!($xpath->registerNamespace($prefix, $rootNamespace))) {
                    echo "Cannot register namespace {$uri}.";
                };

                $xquery  = "/{$prefix}:КоммерческаяИнформация";
                $xquery .= "/{$prefix}:ПакетПредложений";
                $xquery .= "/{$prefix}:Предложения";
                $xquery .= "/{$prefix}:Предложение";

                $products = $xpath->query($xquery);
                if ($products->length > 0) {
                    foreach ($products as $product) {
                        $product_id = $product->getElementsByTagName('Ид')[0]->textContent;
                        $product_name = $product->getElementsByTagName('Наименование')[0]->textContent;

                        $offer_stmt->execute(array(
                            ":product_id"   => $product_id,
                            ":product_name" => $product_name
                        ));

                        $product_count++;
                    }
                }
                echo "\tОбработано " . $products->length . " предложений" . PHP_EOL;
            }
        }
        $end = microtime(true);
        $parse_time = $end-$start;

        echo "Обработано $product_count товаров за {$parse_time}." . PHP_EOL;
    }

    /**
     * Imports Prices
     * @param  boolean $log  Output work result
     * @return boolean       True if records have been inserted, false otherwise
     */
    public static function importPrices($log = false)
    {
        $start = microtime(true);
        // Parent folder with prices' file
        $main_directory = rtrim(self::$dest, '/') . "/webdata/000000001/goods/";
        // Folders that contain files with prices
        $dirs  = array_diff(scandir($main_directory), array('..', '.'));
        asort($dirs);

        $product_count = 0;
        $price_count = 0;

        // Create table `ptplad_product_price`
        $sql  = "DROP TABLE IF EXISTS `ptplad_product_price`; ";
        $sql .= "CREATE TABLE `ptplad_product_price` (";
        $sql .=     "`product_id` varchar(36) NOT NULL,";
        $sql .=     "`price_type_id` varchar(36) NOT NULL,";
        $sql .=     "`value` decimal(10,2) NOT NULL DEFAULT '0.00',";
        $sql .=     "PRIMARY KEY (`product_id`,`price_type_id`)";
        $sql .= ") ENGINE=InnoDB DEFAULT CHARSET=utf8;";
        if (!self::$dbh->query($sql)) {
            echo "Cannot create table `ptplad_product_price`... Exit." . PHP_EOL;
            exit;
        }

        // Prepared SQL statement
        $sql  = "INSERT INTO `ptplad_product_price` (";
        $sql .=     "`product_id`, ";
        $sql .=     "`price_type_id`, ";
        $sql .=     "`value`";
        $sql .= ") VALUES (";
        $sql .=     ":product_id,";
        $sql .=     ":price_type_id,";
        $sql .=     ":price_for_product";
        $sql .= ")";
        $stmt = self::$dbh->prepare($sql);
        if (!$stmt) {
            echo "Cannot create prepared statement... Exit..." . PHP_EOL;
            exit();
        }

        // XPath query
        $xquery  = "/{$prefix}:КоммерческаяИнформация";
        $xquery .= "/{$prefix}:ПакетПредложений";
        $xquery .= "/{$prefix}:Предложения";
        $xquery .= "/{$prefix}:Предложение";

        foreach ($dirs as $key => $dir) {
           chdir($main_directory . $dir);

           if ($dh = opendir($main_directory . $dir)) {
               $files = glob('prices___*');
           };

           foreach ($files as $key => $file) {
               $filename =  $main_directory . $dir . "/" . $file;
               // echo $file . PHP_EOL;

               $doc = \DOMDocument::load($filename);
               $xpath = new \DOMXPath($doc);

               // Register prefix
               $prefix = "ptplad";
               $rootNamespace = $doc->lookupNamespaceUri($doc->namespaceURI);
               if (!($xpath->registerNamespace($prefix, $rootNamespace))) {
                   echo "Cannot register namespace {$uri}.";
               };

               $products = $xpath->query($xquery);
               // echo "File: $file . Products: $products->length" . PHP_EOL;
               if ($products->length > 0) {
                   foreach ($products as $product) {
                       $product_id = $product->getElementsByTagName('Ид')[0]->textContent;
                       $prices = $product->getElementsByTagName('Цена');

                       if ($prices->length > 0) {
                           foreach ($prices as $price) {
                               $price_type_id = $price->getElementsByTagName('ИдТипаЦены')[0]->textContent;
                               $price_for_product = $price->getElementsByTagName('ЦенаЗаЕдиницу')[0]->nodeValue;
                               $currency = $price->getElementsByTagName('Валюта')[0]->textContent;

                               $stmt->execute(array(
                                   ":product_id"        => $product_id,
                                   ":price_type_id"     => $price_type_id,
                                   ":price_for_product" => $price_for_product
                               ));
                               $price_count++;
                           }
                       }
                       $product_count++;
                   }
               }
           }
       }
       $end = microtime(true);
       $parse_time = $end-$start;
       if ($log) {
           echo "Обработано $price_count записей с ценами для $product_count товаров за $parse_time." . PHP_EOL;
       }
       return true;
    }
    
}
