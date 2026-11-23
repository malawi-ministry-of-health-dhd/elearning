<?php
// This file is part of the Academi theme customisations for MoHS LMS.
//
// H5P Course Kit — loads the kit stylesheet inside every mod_hvp activity
// and lets the H5P Text editor keep the tags the kit styles
// (Heading 4 labels, Block quote callouts, tables, sub/superscript).
//
// Moodle finds this class automatically (theme_academi\output\mod_hvp_renderer),
// so theme/academi/renderers.php does not need to change.
// After deploying: Site administration > Development > Purge all caches.

namespace theme_academi\output;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/hvp/renderer.php');

class mod_hvp_renderer extends \mod_hvp_renderer {

    /**
     * Load the Course Kit stylesheet inside every H5P activity.
     * Moodle theme CSS cannot reach into the H5P iframe; this can.
     */
    public function hvp_alter_styles(&$styles, $libraries, $embedtype) {
        global $CFG;

        // Leave the authoring editor untouched.
        if ($embedtype === 'editor') {
            return;
        }

        $styles[] = (object) [
            'path'    => $CFG->wwwroot . '/theme/academi/h5p/h5p-course-kit.css',
            'version' => '?ver=1.1.0', // Bump after editing the CSS so browsers reload it.
        ];
    }

    /**
     * Give the H5P "Text" editor the extra tags the kit styles:
     * Heading 4 (labels), Block quote (callouts), Table, sub/superscript.
     */
    public function hvp_alter_semantics(&$semantics, $name, $majorversion, $minorversion) {
        if ($name !== 'H5P.AdvancedText') {
            return;
        }

        foreach ($semantics as $field) {
            if (isset($field->name) && $field->name === 'text' && isset($field->tags)) {
                $field->tags = array_values(array_unique(array_merge(
                    $field->tags,
                    ['h4', 'blockquote', 'table', 'sub', 'sup']
                )));
            }
        }
    }
}
