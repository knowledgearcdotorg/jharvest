<?php
/**
 * @copyright   Copyright (C) 2014-2017 KnowledgeArc Ltd. All rights reserved.
 * @license     This file is part of the JHarvest component for Joomla!.
 */

defined('_JEXEC') or die;

/**
 * Handles OAI harvesting from the command line.
 *
 * @package     JHarvest.Plugin
 */
class PlgHarvestOai extends JPlugin
{
    const FOLLOW_ON = 0;

    protected $autoloadLanguage = true;

    public function __construct($subject, $config = array())
    {
        parent::__construct($subject, $config);

        \JLog::addLogger(array());
    }

    /**
     * Attempts to discover whether the harvest configuration points to an OAI-enabled url.
     *
     * @param   string     $sourceUrl  The source url to discover.
     *
     * @return  JRegistry  An OAI description as a JRegistry or false if no description can be
     * found.
     */
    public function onJHarvestDiscover($sourceUrl)
    {
        $discovered = false;

        $url = new JUri($sourceUrl);
        $url->setVar('verb', 'Identify');

        $http = JHttpFactory::getHttp();
        $response = $http->get($url);

        $contentType = JArrayHelper::getValue($response->headers, 'Content-Type');
        $contentType = $this->parseContentType($contentType);

        $validContentType = (in_array($contentType, array('text/xml', 'application/xml')) !== false);

        if ((int)$response->code === 200 && $validContentType) {
            $url->setVar('verb', 'ListMetadataFormats');

            $http = JHttpFactory::getHttp();
            $response = $http->get($url);

            if ((int)$response->code === 200) {
                $dom = new DomDocument();
                $dom->loadXML($response->body);

                $nodes = $dom->getElementsByTagName('metadataPrefix');
                $availablePrefixes = array();

                foreach ($nodes as $node) {
                    $availablePrefixes[] = ((string)$node->nodeValue);
                }

                $discovered = new JRegistry;
                $discovered->set('discovery.type', 'oai');
                $discovered->set('discovery.url', (string)$sourceUrl);
            }
        }

        return $discovered;
    }

    /**
     * Retrieves items from an OAI-enabled url.
     *
     * @param  JTable  $harvest  The harvesting details.
     */
    public function onJHarvestRetrieve($harvest)
    {
        $params = new \Joomla\Registry\Registry($harvest->params);

        if ($params->get('discovery.type') != 'oai') {
            return;
        }

        $resumptionToken = null;

        $http = JHttpFactory::getHttp();

        $metadataPrefix = $params->get('discovery.plugin.format.metadata');

        do {
            $queries = array();

            if ($resumptionToken) {
                $queries['resumptionToken'] = $resumptionToken;

                // take a break to avoid any timeout issues.
                if (($sleep = $params->get('follow_on', self::FOLLOW_ON)) != 0) {
                    sleep($sleep);
                }
            } else {
                $queries['metadataPrefix'] = $metadataPrefix;

                if ($harvest->harvested != JFactory::getDbo()->getNullDate()) {
                    $queries['from'] = JFactory::getDate($harvest->harvested)->format('Y-m-d\TH:i:s\Z');
                }

                if ($set = $params->get('set')) {
                    $queries['set'] = $set;
                }

                if ($until = $harvest->until) {
                    $queries['until'] = $until->format('Y-m-d\TH:i:s\Z');
                }
            }

            $url = new JUri($params->get('discovery.url'));
            $url->setQuery($queries);
            $url->setVar('verb', 'ListRecords');

            JHarvestHelper::log('Retrieving '.(string)$url.' for harvest...', JLog::DEBUG);

            $response = $http->get($url);

            $reader = new XMLReader;
            $reader->xml($response->body);

            $prefix = null;
            $identifier = null;
            $resumptionToken = null; // empty the resumptionToken to force a reload per page.

            while ($reader->read()) {
                if ($reader->nodeType == XMLReader::ELEMENT) {
                    $doc = new DOMDocument;

                    $doc->appendChild($doc->importNode($reader->expand(), true));

                    $node = simplexml_load_string($doc->saveXML());

                    $attributes = (array)$node->attributes();

                    if (isset($attributes['@attributes'])) {
                        $attributes = $attributes['@attributes'];
                    }

                    switch ($reader->name) {
                        case "record":
                            try {
                                $this->cache($harvest, $node);
                            } catch (Exception $e) {
                                JHarvestHelper::log($e->getMessage(), JLog::ERROR);
                            }

                            break;

                        case 'responseDate':
                            // only get the response date if fresh harvest.
                            if (!$resumptionToken) {
                                $this->harvested = JFactory::getDate($node);
                            }

                            break;

                        case 'request':
                            $prefix = JArrayHelper::getValue($attributes, 'metadataPrefix', null, 'string');

                            break;

                        case 'error':
                            if (JArrayHelper::getValue($attributes, 'code', null, 'string') !== "noRecordsMatch") {
                                throw new Exception((string)$node, 500);
                            }

                            break;

                        case 'resumptionToken':
                            $resumptionToken = (string)$node;
                            break;

                        default:
                            break;
                    }
                }
            }
        } while ($resumptionToken);
    }

    /**
     * Caches a harvested record.
     *
     * @param  JObject           $harvest  The harvest configuration.
     * @param  SimpleXmlElement  $data     An OAI record as an instance of the SimpleXmlElement class.
     */
    protected function cache($harvest, $data)
    {
        $params = new \Joomla\Registry\Registry($harvest->params);

        if (isset($data->header->identifier)) {
            if (isset($data->header["status"])) {
                switch ($data->header["status"]) {
                    case "deleted":
                        $state = -2; // DELETED

                        break;
                }
            } else {
                $context = 'joaipmh.'.$params->get('discovery.plugin.format.metadata');

                $dispatcher = JEventDispatcher::getInstance();
                JPluginHelper::importPlugin("joaipmh");

                $array = $dispatcher->trigger('onJOaiPmhHarvestMetadata', [$context, $data->metadata]);

                $cache = array("metadata"=>JArrayHelper::getValue($array, 0));

                // probe for assets (if an ORE plugin is enabled)
                $metadataPrefix = $params->get('discovery.plugin.format.assets');

                if ($metadataPrefix) {
                    $queries = array(
                        'verb'=>'GetRecord',
                        'identifier'=>(string)$data->header->identifier,
                        'metadataPrefix'=>$metadataPrefix);

                    $url = new JUri($params->get('discovery.url'));
                    $url->setQuery($queries);

                    $http = JHttpFactory::getHttp();
                    $response = $http->get($url);

                    if ((int)$response->code == 200) {
                        $context = 'joaiore.'.$metadataPrefix;

                        $node = simplexml_load_string($response->body);

                        JPluginHelper::importPlugin("joaiore");

                        $array = $dispatcher->trigger('onJOaiOreHarvestAssets', array($context, $node));

                        $cache["assets"] = JArrayHelper::getValue($array, 0, array());
                    } else if ((int)$response->code == 404) {
                        JLog::add("No valid ORE endpoint found. Continuing without files...");
                    } else {
                        throw new Exception ((string)$response, (int)$response->code);
                    }
                }
                
                $state = 0; // HARVESTED
            }
            
            // @TODO move this back into component, maybe as Helper::cache($data);
            $table = JTable::getInstance('Cache', 'JHarvestTable');
            $table->set('id', (string)$data->header->identifier);
            
            if (isset($cache)) {
                $table->set('data', json_encode($cache));
            }
            
            $table->set('harvest_id', (int)$harvest->id);
            $table->set('state', $state);
            $table->store();
        }
    }

    /**
     * Parse the content type, removing any additional type settings.
     *
     * @param   string  $contentType  The content type to parse.
     *
     * @return  string  The parsed content type.
     */
    protected function parseContentType($contentType)
    {
        $parts = explode(';', $contentType);
        return trim(\JArrayHelper::getValue($parts, 0));
    }
}
