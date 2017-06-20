<?php
/**
 * @copyright   Copyright (C) 2014-2017 KnowledgeArc Ltd. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

defined('_JEXEC') or die;

use \Joomla\Utilities\ArrayHelper;

JLoader::import('joomla.filesystem.folder');

/**
 * Ingest metadata using Joomla articles.
 *
 * @package     JHarvest.Plugin
 */
class PlgIngestArticle extends JPlugin
{
    protected $autoloadLanguage = true;

    public function onJHarvestIngest($items, $params)
    {
        if (!$this->params->get('user_id')) {
            throw new Exception(JText::_("PLG_INGEST_ARTICLE_NO_USER"));
        }

        $user = \JFactory::getUser($this->params->get('user_id'));
        \JFactory::getSession()->set('user', $user);

        // A general test as to whether the Article Manager allows the current
        // user to edit custom field values.
        if (!$user->authorise('core.edit.value', "com_content")) {
            throw new Exception(JText::_("PLG_INGEST_ARTICLE_EDIT_FIELD_VALUES_NOT_ALLOWED"));
        }

        JModelLegacy::addIncludePath(JPATH_ADMINISTRATOR.'/components/com_content/models', 'ContentModel');

        $languages = \JLanguageHelper::getContentLanguages();

        foreach ($items as $item) {
            $itemData = json_decode($item->data);

            $metadata = $itemData->metadata;
            $assets = $itemData->assets;

            $article = JModelLegacy::getInstance("Article", "ContentModel", ["ignore_request"=>true]);

            $data = [];

            if (isset($metadata->title) && !is_null($metadata->title)) {
                $data["title"] = array_shift($metadata->title);
            } else {
                $data["title"] = "Undefined";
            }

            if (isset($metadata->description) && !is_null($metadata->description)) {
                $data["description"] = array_shift($metadata->description);
            }

            if (isset($metadata->language) && !is_null($metadata->language)) {
                $found = false;
                $language = array_shift($metadata->language);

                reset($languages);

                while (!$found && $lang = current($languages)) {
                    $code = $lang->lang_code;

                    $match = ($code == $language);
                    $nearMatch = (strlen($language) == 2 && strpos($code, $language) === 0);

                    if ($match || $nearMatch) {
                        $found = $lang->lang_code;
                    }

                    next($languages);
                }

                if ($found) {
                    $data["language"] = $found;
                } else {
                    $data["language"] = "*";
                }
            } else {
                $data["language"] = "*";
            }

            $data["catid"] = $params->get('ingest.article.catid');
            $data["alias"] = null;

            foreach (ArrayHelper::fromObject($metadata) as $key=>$value) {
                $i = 0;

                foreach ($value as $v) {
                    $data["com_fields"][$key]["$key".$i] = $v;
                    $i++;
                }
            }

            // enables the field after save event.
            JPluginHelper::importPlugin('system');

            // trick com_content into generating new aliases and handling duplicate
            // titles.
            JFactory::getApplication()->input->set("task", "save");

            if (!$article->save($data)) {
                echo $article->getError();
            }

            $article->delete($article->getItem()->id);
        }
    }
}
