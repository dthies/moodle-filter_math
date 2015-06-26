<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Moodle - Filter for scanning document for various math tags and preparing
 *    them to be processed.
 *
 * @subpackage math
 * @copyright  2014 Daniel Thies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

/**
 * Math filtering class.
 */
class filter_math extends moodle_text_filter {

    public $delimiters = array();
    public $pattern;

    public function filter($text, array $options = array()) {

        global $CFG, $DB;

        // Do a quick check for presence of delimiters and set pattern.
        if (!$this->set_delimiters($text)) {
            return $text;
        }

        // Create a new dom object.
        $dom = new domDocument;
        $this->dom = $dom;
        $dom->formatOutput = true;

        // Load the html into the objecti.
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<!DOCTYPE html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"/></head><body>' .
            $text);
        libxml_use_internal_errors(false);

        $dom->preserveWhiteSpace = false;
        $dom->strictErrorChecking = false;
        $dom->recover = true;

        $body = $dom->getElementsByTagName('body')->item(0);
        // Replace delimiters inside text.
        $this->replace_math_tags($body);

        $this->process();

        $str = $dom->saveHTML($body);
        $str = str_replace("<body>", "", $str);
        $str = str_replace("</body>", "", $str);

        return $str;

    }

    /**
     * Find and replace script delimiters with span nodes in DOM
     *
     * @param DOMElement $node
     */

    private function replace_math_tags($node) {
        if (!$node->hasChildNodes()) {
            return;
        }
        $text = '';
        $children = $node->childNodes;
        for ($i = 0; $i < $children->length; ++$i) {
            $type = $children->item($i)->nodeName;
            switch ($type) {
                case 'p':
                case 'span':
                case 'button':
                case 'div':
                    $this->replace_math_tags($children->item($i));
                    break;
                case '#text':
                    $text .= $children->item($i)->nodeValue;
            }
        }
        if (!preg_match_all('/' . $this->pattern . '/s', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return;
        }
        $endtext = strlen($text);
        $i = $children->length;
        $match = array_pop($matches[0]);

        while ($i > 0) {
            $child = $children->item(--$i);
            $type = $child->nodeName;
            if ($type == '#text') {
                $endtext -= strlen($child->nodeValue);
                while ($endtext <= $match[1] + strlen($match[0])) {
                    $child->splitText($match[1] + strlen($match[0]) - $endtext);
                    $child->nodeValue = $child->nodeValue;
                    $container = $node->ownerDocument->createElement('span');
                    $node->insertBefore($container, $child->nextSibling);
                    while ($endtext > $match[1]) {
                        $container->insertBefore($child, $container->firstChild);
                        $child = $children->item(--$i);
                        $type = $child->nodeName;
                        if ($type == '#text') {
                            $endtext -= strlen($child->nodeValue);
                        }
                    }
                    $child->splitText($match[1] - $endtext);
                    $child->nextSibling->nodeValue = $child->nextSibling->nodeValue;
                    $container->insertBefore($child->nextSibling, $container->firstChild);
                    foreach ($this->delimiters as $d) {
                        if (preg_match('/^' . preg_quote($d[0]) . '(.+?)' . preg_quote($d[1]) . '$/s', $container->nodeValue)) {
                            $container->setAttribute('class', 'local-math-' . $d[2]);
                            $container->firstChild->nodeValue = preg_replace(
                                '/^' .  preg_quote($d[0]) . '/', '',
                                $container->firstChild->nodeValue);
                            $container->lastChild->nodeValue = preg_replace(
                                '/' . preg_quote($d[1]) . '$/', '',
                                $container->lastChild->nodeValue);
                        }
                    }
                    $match = array_pop($matches[0]);
                    if ($match == null) {
                        break;
                    }
                }
            }
        }
    }

    private function process () {
        $spans = $this->dom->getElementsByTagName('span');
        for ($i = $spans->length; $i > 0; --$i) {
            $span = $spans->item($i - 1);
            if ($span->hasAttribute('class')) {
                if (key_exists($span->getAttribute('class'), $this->subplugins)) {
                    $this->subplugins[$span->getAttribute('class')]->process($span);
                }
            }
        }
    }

    private function set_delimiters($text) {
        $disabledsubplugins = array();
        if (get_config('local_math', 'disabledsubplugins')) {
            $disabledsubplugins = explode(',', get_config('local_math', 'disabledsubplugins'));
            foreach ($disabledsubplugins as $key => $subplugin) {
                $disabledsubplugins[$key] = trim($subplugin);
            }
        }
        $search = array();
        $this->delimiters = array();
        $this->subplugins = array();
        $subplugins = core_component::get_plugin_list('math');
        foreach ($subplugins as $name => $dir) {
            $subplugin = $this->get_plugin($name);
            $delimiters = json_decode($subplugin->get_config('delimiters'), true);
            if (!count($delimiters) or in_array($name, $disabledsubplugins)) {
                continue;
            }

            foreach ($delimiters as $d) {
                if (strrpos($text, $d[1])) {
                    $search[] = preg_quote($d[0]) . '(.+?)' . preg_quote($d[1]);
                    $d[] = $name;
                    $this->delimiters[] = $d;
                    $this->subplugins['local-math-' . $name] = $this->get_plugin($name);
                }
            }
        }
        $this->pattern = implode('|', $search);
        return count($search);
    }

    public function get_plugin($plugin) {
        return local_math_plugin::get($plugin);
    }

}
