<?php

/*
 * Copyright (C) 2018 Zmyslni
 *
 * @author  Ostrowski Maciej <http://contao-developer.pl>
 * @author  Ostrowski Maciej <maciek@zmyslni.pl>
 * @license LGPL-3.0+
 */

namespace Zmyslny\WrapperTags\EventListener;

use Contao\DataContainer;
use ReflectionClass;

/**
 * Class ContentListener
 * @package Zmyslny\WrapperTags\EventListener
 */
class ContentListener extends \tl_content
{
    /**
     * On record save callback.
     *
     * @param $data
     * @param DataContainer $dc
     * @return string
     * @throws \Exception
     */
    public function onSaveCallback($data, DataContainer $dc)
    {
        $tags = deserialize($data);

        foreach ($tags as &$tag) {

            // Validate attributes
            if ($tag['attributes']) {

                $attributes = [];

                foreach ($tag['attributes'] as $attribute) {

                    // The attribute name must not contain any whitespace
                    $attribute['name'] = preg_replace('/\s+/', '', $attribute['name']);
                    $attribute['value'] = trim($attribute['value']);

                    if ('' !== $attribute['name'] && '' === $attribute['value']) {
                        throw new \Exception('The attribute name "' . $attribute['name'] . '" is without a value');
                    }

                    if ('' === $attribute['name'] && '' !== $attribute['value']) {
                        throw new \Exception('The attribute value "' . $attribute['value'] . '" is without a name');
                    }

                    if (is_numeric($attribute['name'])) {
                        throw new \Exception('The attribute name must not be a number');
                    }

                    // Allow attributes with non-empty name & value
                    if ('' !== $attribute['name'] && '' !== $attribute['value']) {
                        $attributes[] = $attribute;
                    }
                }

                $tag['attributes'] = $attributes;
            }
        }

        return serialize($tags);
    }

    /**
     * Set html class on each CTE from list view.
     *
     * Class being set in this function will be set to the next CTE then CTE of $row element. That is why
     * $GLOBALS['WrapperTags']['indents'] array was offset so every cteId point to class of the next element.
     *
     * @param $row
     * @return string
     */
    public function onChildRecordCallback($row)
    {
        if (isset($GLOBALS['WrapperTags']['indents']) && is_array($GLOBALS['WrapperTags']['indents'])) {

            $indent = $GLOBALS['WrapperTags']['indents'][$row['id']];

            if (null !== $indent) {
                $this->setChildRecordClass($indent);
            }
        }

        // standard Contao child-record-callback
        return parent::addCteType($row);
    }

    /**
     * Sets css class into "child_record_class" setting.
     *
     * @param array $indent
     */
    protected function setChildRecordClass($indent)
    {
        $wrapperTagClass = $indent['type'] === 'openingTags' || $indent['type'] === 'closingTags' ? 'wrapper-tag' : '';
        $middleClass = (isset($indent['middle'])) ? ' indent-tags-closing-middle' : '';

        $GLOBALS['TL_DCA']['tl_content']['list']['sorting']['child_record_class'] = $indent['value'] > 0 ? 'clear-indent ' . $wrapperTagClass . ' indent indent_' . $indent['value'] . $middleClass . ' ' . $indent['colorize-class'] : 'clear-indent ' . $wrapperTagClass . ' indent_0 ' . $middleClass;
    }

    /**
     * On columns callback of multiColumnWizard field 'closingTags'.
     *
     * @return array
     */
    public function onClosingTagsColumnsCallback()
    {
        return array(
            'tag' => array
            (
                'label' => &$GLOBALS['TL_LANG']['tl_content']['wrapperTagsTag'],
                'inputType' => 'select',
                'options_callback' => array('Zmyslny\WrapperTags\EventListener\ContentListener', 'getTags'),
            )
        );
    }

    /**
     * Returns html tags allowed for wrapper tags.
     *
     * @return array
     */
    public function getTags()
    {
        $tags = trimsplit('><', \Config::get('wrapperTagsAllowedTags'));
        $tags[0] = str_replace('<', '', $tags[0]);
        $tags[count($tags) - 1] = str_replace('>', '', $tags[count($tags) - 1]);

        return $tags;
    }

    /**
     * On header callback. Checks whether every start wrapper has its corresponding stop wrapper, recalculates indents,
     * sets css color classes.
     *
     * @param $add
     * @param DataContainer $dc
     * @return array
     */
    public function onHeaderCallback($add, DataContainer $dc)
    {
        /*
         * Check whether there is any published wrapper-tags cte.
         * Do not use $dc->id to get pid id because in copy mode it is id of element being copied.
         * Instead use CURRENT_ID.
         */
        $result = $this->Database
            ->prepare("
                SELECT id
                FROM `tl_content`
                WHERE pid = ? AND ptable = ? AND invisible != ? AND type IN ('openingTags','closingTags')
                ")
            ->execute(CURRENT_ID, $dc->parentTable, '1');

        if ($result->numRows === 0) {

            // no published wrapper-tags elements in this article
            return $add;
        }

        // get all content elements from this article
        $query = '
            SELECT id, type, openingTags, closingTags, invisible
            FROM `tl_content`
            WHERE pid = ? AND ptable = ?
            ORDER BY sorting ASC
        ';

        $stmt = $this->Database->prepare($query);

        // ! do not set limit - validation needs all elements

        $result = $stmt->execute(CURRENT_ID, $dc->parentTable);

        $statusTitle = $GLOBALS['TL_LANG']['MSC']['wt.statusTitle'];
        $status = array();

        if ($result->numRows === 0) {
            $status[$statusTitle] = '<span class="tl_red">' . $GLOBALS['TL_LANG']['MSC']['wrapperTagsValidationError'] . '</span>';
            return $add + $status;
        }

        $indentLevel = 0;
        $openStack = array();
        $status = array();


        // helps to show only the first error
        $hasError = false;

        $hideStatus = \Config::get('wrapperTagsHideValidationStatus');
        if ($hideStatus) {
            // it turns off validation checking because algorithm will think it already has first error
            $hasError = true;
        }

        foreach ($result->fetchAllAssoc() as $cte) {

            $isWrapperStart = in_array($cte['type'], $GLOBALS['TL_WRAPPERS']['start']);
            $isWrapperStop = in_array($cte['type'], $GLOBALS['TL_WRAPPERS']['stop']);
            $isVisible = $cte['invisible'] !== '1';

            if ($isWrapperStart) {

                $this->wrapperStart($cte, $isVisible, $statusTitle, $openStack, $indentLevel, $hasError, $status);

            } elseif ($isWrapperStop) {

                $this->wrapperStop($cte, $isVisible, $statusTitle, $openStack, $indentLevel, $hasError, $status);

            } else {

                // not a wrapper element
                $GLOBALS['WrapperTags']['indents'][$cte['id']] = array('type' => $cte['type'], 'value' => $indentLevel);
            }

        }

        if (!$hasError) {

            if (count($openStack)) {

                // check whether there is openingTags element on stack
                for ($i = count($openStack) - 1; $i >= 0; --$i) {

                    if ($openStack[$i]['type'] === 'openingTags') {

                        $status[$statusTitle] = '<span class="tl_red">' . sprintf($GLOBALS['TL_LANG']['MSC']['wrapperTagsStatusOpeningNoClosing'], $openStack[$i]['tags'][count($openStack[$i]['tags']) - 1]['tag'], $openStack[$i]['id']) . '</span>';
                        $hasError = true;
                        break;
                    }
                }
            }
        }

        if (!$hasError) {
            $status[$statusTitle] = $GLOBALS['TL_LANG']['MSC']['wrapperTagsStatusOk'];
        }

        // hide validation status
        if ($hideStatus) {
            $status = array();
        }

        $useColors = \Config::get('wrapperTagsUseColors');

        /*
         * Indents will be used in childRecordCallback.
         *
         * First element child_record_class must be set before child_record_callback is called, e.g. in this function.
         */

        if (class_exists('ReflectionClass')) {

            // need to use ReflectionClass in order to get $dc->limit property

            $reflectionClass = new ReflectionClass('DC_Table');
            $reflectionProperty = $reflectionClass->getProperty('limit');
            $reflectionProperty->setAccessible(true);
            $limit = $reflectionProperty->getValue($dc);

            if (strlen($limit)) {
                $limit = explode(',', $limit);
                $offset = (int)$limit[0];

                // set child_record_class for first CTE on list view - paging is taken into account
                if ($offset > 0) {
                    $index = 1;
                    $firstElementOnPage = $offset + 1;
                    foreach ($GLOBALS['WrapperTags']['indents'] as $indent) {
                        if ($index === $firstElementOnPage) {
                            $this->setChildRecordClass($indent + array('colorize-class' => ($useColors ? 'colorize-wrapper-tags' : '')));
                            break;
                        }
                        ++$index;
                    }
                }

            } else {
                $this->setChildRecordClass($GLOBALS['WrapperTags']['indents'][key($GLOBALS['WrapperTags']['indents'])]);
            }
        }

        /*
         * When we set child_record_class in child_record_callback, it will be set for the next element then element
         * for which that function is called. So indent values must be offset. Every element id must point to the indent
         * value of the next element.
         */

        end($GLOBALS['WrapperTags']['indents']);
        $lastKey = key($GLOBALS['WrapperTags']['indents']);
        $lastIndent = $GLOBALS['WrapperTags']['indents'][$lastKey];

        $reversed = array_reverse($GLOBALS['WrapperTags']['indents'], true);

        foreach ($reversed as $id => &$indent) {
            $nowIndent = $indent;
            $indent = $lastIndent + array('colorize-class' => ($useColors ? 'colorize-wrapper-tags' : ''));
            $lastIndent = $nowIndent;
        }

        $GLOBALS['WrapperTags']['indents'] = array_reverse($reversed, true);

        return $add + $status;
    }

    /**
     * @param $cte
     * @param $isVisible
     * @param $statusTitle
     * @param $openStack
     * @param $indentLevel
     * @param $hasError
     * @param $status
     */
    protected function wrapperStart($cte, $isVisible, $statusTitle, &$openStack, &$indentLevel, &$hasError, &$status)
    {
        if ('openingTags' !== $cte['type']) {

            if ($isVisible) {

                // put wrapper start (whatever type it is) on stack
                $openStack[] = array(
                    'id' => $cte['id'],
                    'type' => $cte['type']
                );

                $GLOBALS['WrapperTags']['indents'][$cte['id']] = array('type' => $cte['type'], 'value' => $indentLevel);
                ++$indentLevel;

            } else {

                $GLOBALS['WrapperTags']['indents'][$cte['id']] = array('type' => $cte['type'], 'value' => $indentLevel);
            }

        } else {

            if ($isVisible) {

                // every opened tag from openingTags put on stack

                $startTags = unserialize($cte['openingTags']);

                if (!$hasError) {
                    if (!is_array($startTags)) {
                        $status[$statusTitle] = '<span class="tl_red">' . $GLOBALS['TL_LANG']['MSC']['wrapperTagsDataCorrupted'] . '</span>';
                        $hasError = true;
                    }
                }

                $openStack[] = array(
                    'id' => $cte['id'],
                    'type' => 'openingTags',
                    'tags' => $startTags,
                    'count' => count($startTags)
                );

                $GLOBALS['WrapperTags']['indents'][$cte['id']] = array('type' => $cte['type'], 'value' => $indentLevel);
                ++$indentLevel;

            } else {

                $GLOBALS['WrapperTags']['indents'][$cte['id']] = array('type' => $cte['type'], 'value' => $indentLevel);
            }
        }
    }

    /**
     * @param $cte
     * @param $isVisible
     * @param $statusTitle
     * @param $openStack
     * @param $indentLevel
     * @param $hasError
     * @param $status
     */
    protected function wrapperStop($cte, $isVisible, $statusTitle, &$openStack, &$indentLevel, &$hasError, &$status)
    {
        if ('closingTags' !== $cte['type']) {

            if ($isVisible) {

                $openingTags = $openStack[count($openStack) - 1];

                if (!$hasError) {

                    // Last opened wrapper is of type 'openingTags'. Because now we are stepping on closing element
                    // not of type 'closingTags' so the pairing is wrong.
                    if ($openingTags !== null && $openingTags['type'] === 'openingTags') {

                        $status[$statusTitle] = '<span class="tl_red">' . sprintf($GLOBALS['TL_LANG']['MSC']['wrapperTagsStatusOpeningWrongPairingWithOther'], $openingTags['tags'][count($openingTags['tags']) - 1]['tag'], $openingTags['id'], $GLOBALS['TL_LANG']['CTE'][$cte['type']][0], $cte['id']) . '</span>';
                        $hasError = true;
                    }
                }

                array_pop($openStack);
                --$indentLevel;
            }

            $GLOBALS['WrapperTags']['indents'][$cte['id']] = array('type' => $cte['type'], 'value' => $indentLevel);

        } else {

            $GLOBALS['WrapperTags']['indents'][$cte['id']] = array('type' => $cte['type'], 'value' => ($indentLevel > 1 ? $indentLevel - 1 : 0));

            if (!$isVisible) {

                $GLOBALS['WrapperTags']['indents'][$cte['id']]['value'] += $indentLevel > 0 ? 1 : 0;

            } else {

                $closingTags = unserialize($cte['closingTags']);

                if (!$hasError) {
                    if (!is_array($closingTags)) {
                        $status[$statusTitle] = '<span class="tl_red">' . $GLOBALS['TL_LANG']['MSC']['wrapperTagsDataCorrupted'] . '</span>';
                        $hasError = true;
                    }
                }

                if (count($openStack) === 0) {

                    /*
                     * case 1: no more opened tags on stack
                     */

                    if (!$hasError) {
                        $status[$statusTitle] = '<span class="tl_red">' . sprintf($GLOBALS['TL_LANG']['MSC']['wrapperTagsStatusClosingNoOpening'], $closingTags[count($closingTags) - 1]['tag'], $cte['id']) . '</span>';
                        $hasError = true;
                    }

                } elseif ('openingTags' !== $openStack[count($openStack) - 1]['type']) {

                    /*
                     * case 2: closing element is paired with wrong opening element - it is not of type 'openingTags'
                     */

                    $openingTags = array_pop($openStack);

                    if (!$hasError) {
                        $status[$statusTitle] = '<span class="tl_red">' . sprintf($GLOBALS['TL_LANG']['MSC']['wrapperTagsStatusClosingWrongPairingWithOther'], $closingTags[0]['tag'], $cte['id'], $GLOBALS['TL_LANG']['CTE'][$openingTags['type']][0], $openingTags['id']) . '</span>';
                        $hasError = true;
                    }

                    --$indentLevel;

                } else {

                    /*
                     * case 3: proper pairing with type 'openingTags'
                     */

                    if ($openStack[count($openStack) - 1]['count'] >= count($closingTags)) {

                        /*
                         * case 3.1: ONE big openingTags element paired with ONE or MORE smaller closingTags elements
                         */

                        $this->wrapperStopOneToMany($cte, $closingTags, $statusTitle, $openStack, $indentLevel, $hasError, $status);

                    } else {

                        /*
                         * case 3.2: MANY small openingTags elements paired with ONE bigger closingTags element
                         */

                        $this->wrapperStopManyToOne($cte, $closingTags, $statusTitle, $openStack, $indentLevel, $hasError, $status);
                    }
                }
            }
        }
    }

    /**
     * @param $cte
     * @param $closingTags
     * @param $statusTitle
     * @param $openStack
     * @param $indentLevel
     * @param $hasError
     * @param $status
     */
    protected function wrapperStopOneToMany($cte, $closingTags, $statusTitle, &$openStack, &$indentLevel, &$hasError, &$status)
    {
        $openingWasPaired = false;

        foreach ($closingTags as $closingTag) {

            if (count($openStack) === 0) {

                if (!$hasError) {
                    $status[$statusTitle] = '<span class="tl_red">' . sprintf($GLOBALS['TL_LANG']['MSC']['wrapperTagsStatusClosingNoOpening'], $closingTag['tag'], $cte['id']) . '</span>';
                    $hasError = true;
                }

                break;
            }

            $openingTag = array_pop($openStack[count($openStack) - 1]['tags']);

            if ($closingTag['tag'] !== $openingTag['tag']) {

                if (!$hasError) {
                    $status[$statusTitle] = '<span class="tl_red">' . sprintf($GLOBALS['TL_LANG']['MSC']['wrapperTagsStatusOpeningWrongPairing'], $openingTag['tag'], $openStack[count($openStack) - 1]['id'], $closingTag['tag'], $cte['id']) . '</span>';
                    $hasError = true;
                }
            }

            if (count($openStack[count($openStack) - 1]['tags']) === 0) {

                array_pop($openStack);
                --$indentLevel;

                $openingWasPaired = true;
            }
        }

        if (!$openingWasPaired) {
            // closingTag element was not the last one, it is the middle closingTags element
            $GLOBALS['WrapperTags']['indents'][$cte['id']]['middle'] = true;
        }
    }

    /**
     * @param $cte
     * @param $closingTags
     * @param $statusTitle
     * @param $openStack
     * @param $indentLevel
     * @param $hasError
     * @param $status
     */
    protected function wrapperStopManyToOne($cte, $closingTags, $statusTitle, &$openStack, &$indentLevel, &$hasError, &$status)
    {
        $indentDown = 0;
        $lastPairedId = 0;
        $lastElementWasPaired = false;
        $openingTagsPaired = array();

        foreach ($closingTags as $closingTag) {

            if (count($openStack) === 0) {

                if (!$hasError) {
                    $status[$statusTitle] = '<span class="tl_red">' . sprintf($GLOBALS['TL_LANG']['MSC']['wrapperTagsStatusClosingNoOpening'], $closingTag['tag'], $cte['id']) . '</span>';
                    $hasError = true;
                }

                break;

            } else {
                $lastElementWasPaired = false;
            }

            $openingTags = &$openStack[count($openStack) - 1];
            $openingTag = array_pop($openingTags['tags']);
            $lastPairedId = (int)$openingTags['id'];

            if ($closingTag['tag'] !== $openingTag['tag']) {

                if (!$hasError) {
                    $status[$statusTitle] = '<span class="tl_red">' . sprintf($GLOBALS['TL_LANG']['MSC']['wrapperTagsStatusOpeningWrongPairing'], $openingTag['tag'], $openingTags['id'], $closingTag['tag'], $cte['id']) . '</span>';
                    $hasError = true;
                }
            }

            if (count($openingTags['tags']) === 0) {

                $openingTagsPaired[$openingTags['id']] = $openingTags;
                array_pop($openStack);
                $lastElementWasPaired = true;

                ++$indentDown;
            }
        }

        // Last paired opening tags element is still has not paired, has single html tag left
        if (!$lastElementWasPaired) {

            if (!$hasError) {
                $status[$statusTitle] = '<span class="tl_red">' . sprintf($GLOBALS['TL_LANG']['MSC']['wrapperTagsStatusClosingWrongPairingNeedSplit'], $cte['id'], $openStack[count($openStack) - 1]['id']) . '</span>';
                $hasError = true;
            }

            ++$indentDown;
        }

        $indentLevel = $indentLevel - $indentDown;
        $GLOBALS['WrapperTags']['indents'][$cte['id']] = array('type' => $cte['type'], 'value' => $indentLevel);

        // iterate backwards and correct indents
        $ids = array_keys($GLOBALS['WrapperTags']['indents']);
        for ($i = count($ids) - 2; $i >= 0; --$i) {

            if ($ids[$i] === $lastPairedId) {
                break;
            }

            $element = &$GLOBALS['WrapperTags']['indents'][$ids[$i]];
            $element['value'] = $element['value'] - $indentDown + 1;

            if (isset($openingTagsPaired[$ids[$i]])) {
                --$indentDown;
            }
        }
    }
}