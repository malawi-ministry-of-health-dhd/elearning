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
 * Course renderer.
 *
 * @package theme_academi
 * @copyright 2023 onwards LMSACE Dev Team (http://www.lmsace.com)
 * @author LMSACE Dev Team
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace theme_academi\output\core;

use html_writer;
use moodle_url;
use lang_string;
use stdClass;
use context_course;

/**
 * The core course renderer.
 *
 * Can be retrieved with the following:
 * $renderer = $PAGE->get_renderer('core','course');
 */
class course_renderer extends \core_course_renderer {

    /**
     * Call the frontpage slider js.
     * @param string $blockid
     * @return void
     */
    public function include_frontslide_js($blockid) {
        $this->page->requires->js_call_amd('theme_academi/frontpage', $blockid, []);
    }


    /**
     * Returns HTML to print list of available courses for the frontpage.
     *
     * @return string
     */
    public function frontpage_available_courses() {
        global $CFG;
        $displayoption = theme_academi_get_setting('availablecoursetype');
        if ($displayoption != '1') {
            return parent::frontpage_available_courses();
        }

        $chelper = new \coursecat_helper();
        $chelper->set_show_courses(self::COURSECAT_SHOW_COURSES_EXPANDED)->set_courses_display_options(
                [
                    'recursive' => true,
                    'limit' => $CFG->frontpagecourselimit,
                    'viewmoreurl' => new moodle_url('/course/index.php'),
                    'viewmoretext' => new lang_string('fulllistofcourses'),
                ]);

        $chelper->set_attributes(['class' => 'frontpage-course-list-all']);
        $courses = \core_course_category::top()->get_courses($chelper->get_courses_display_options());
        $totalcount = \core_course_category::top()->get_courses_count($chelper->get_courses_display_options());
        if (!$totalcount && !$this->page->user_is_editing() &&
            has_capability('moodle/course:create', \context_system::instance())) {
            // Print link to create a new course, for the 1st available category.
            return $this->add_new_course_button();
        }
        if (!empty($courses)) {
            $data = [];
            $attributes = $chelper->get_and_erase_attributes('courses');
            $content = \html_writer::start_tag('div', $attributes);
            foreach ($courses as $course) {
                $data[] = $this->available_coursebox($chelper, $course);
            }
            $totalcourse = count($data);
            $content .= $this->render_template('availablecourses', ['courses' => $data, 'totalavacount' => $totalcourse]);
            $content .= \html_writer::end_tag('div');
            $this->include_frontslide_js('availablecourses');
            return $content;
        }
    }

    /**
     * Return contents for the available course block on the frontpage.
     *
     * @param coursecat_helper $chelper course helper.
     * @param array $course course detials.
     *
     * @return array $data available course data.
     */
    public function available_coursebox(\coursecat_helper $chelper, $course) {
        global $CFG;
        $coursename = $chelper->get_course_formatted_name($course);
        $data['name'] = $coursename;
        $data['link'] = new \moodle_url('/course/view.php', ['id' => $course->id]);
        $noimgurl = $this->output->image_url('no-image', 'theme');
        foreach ($course->get_course_overviewfiles() as $file) {
            $isimage = $file->is_valid_image();
            $imgurl = file_encode_url("$CFG->wwwroot/pluginfile.php",
                '/'. $file->get_contextid(). '/'. $file->get_component(). '/'.
                $file->get_filearea(). $file->get_filepath(). $file->get_filename(), !$isimage);
            if (!$isimage) {
                $imgurl = $noimgurl;
            }
        }
        if (empty($imgurl)) {
            $imgurl = $noimgurl;
        }
        $data['imgurl'] = $imgurl;
        return $data;
    }

    /**
     * Render the template.
     *
     * @param string $template name of the template.
     * @param array $data Data.
     *
     * @return string.
     */
    public function render_template($template, $data) {
        $data[$template] = 1;
        $data['ouput'] = $this->output;
        return $this->output->render_from_template('theme_academi/course_blocks', $data);
    }

    /**
     * Promoted course content for the theme front page.
     *
     * @return string
     */
    public function promoted_courses() {
        global $CFG , $DB;

        $pcoursestatus = theme_academi_get_setting('pcoursestatus');
        if (!$pcoursestatus) {
            return false;
        }
        /* Get Featured courses id from DB */
        $featuredids = theme_academi_get_setting('promotedcourses');
        $rcourseids = (!empty($featuredids)) ? explode(",", $featuredids) : [];
        if (empty($rcourseids)) {
            return false;
        }
        $helperobj = new \theme_academi\helper();
        $hcourseids = $helperobj->hidden_courses_ids();

        if (!empty($hcourseids)) {
            foreach ($rcourseids as $key => $val) {
                if (in_array($val, $hcourseids)) {
                    unset($rcourseids[$key]);
                }
            }
        }

        foreach ($rcourseids as $key => $val) {
            $ccourse = $DB->get_record('course', ['id' => $val]);
            if (empty($ccourse)) {
                unset($rcourseids[$key]);
                continue;
            }
        }

        if (empty($rcourseids)) {
            return false;
        }

        $fcourseids = $rcourseids;
        $totalfcourse = count($fcourseids);
        $promotedtitle = theme_academi_get_setting('promotedtitle', 'format_html');
        $promotedtitle = theme_academi_lang($promotedtitle);
        $promotedcoursedesc = theme_academi_lang(theme_academi_get_setting('promotedcoursedesc'));

        if (!empty($fcourseids)) {
            $blocks = [];
            $i = 0;
            foreach ($fcourseids as $courseid) {
                $info = [];
                $course = get_course($courseid);
                $noimgurl = $this->output->image_url('no-image', 'theme');
                $courseurl = new moodle_url('/course/view.php', ['id' => $courseid]);

                if ($course instanceof stdClass) {
                    $course = new \core_course_list_element($course);
                }

                $imgurl = '';
                $summary = $helperobj->strip_html_tags($course->summary);
                $summary = $helperobj->course_trim_char($summary, 75);
                foreach ($course->get_course_overviewfiles() as $file) {
                    $isimage = $file->is_valid_image();
                    $imgurl = file_encode_url("$CFG->wwwroot/pluginfile.php",
                    '/'. $file->get_contextid(). '/'. $file->get_component(). '/'.
                    $file->get_filearea(). $file->get_filepath(). $file->get_filename(), !$isimage);
                    if (!$isimage) {
                        $imgurl = $noimgurl;
                    }
                }
                if (empty($imgurl)) {
                    $imgurl = $noimgurl;
                }
                $info['courseurl'] = $courseurl;
                $info['imgurl'] = $imgurl;
                $info['coursename'] = $course->get_formatted_name();
                $info['active'] = ($i == 1) ? true : false;
                $blocks[] = $info;
                $i++;
            }
        }
        $template['courses'] = array_chunk($blocks, 5);
        $template['promatedcourse'] = true;
        $template['promotedtitle'] = $promotedtitle;
        $template['promotedcoursedesc'] = $promotedcoursedesc;
        $template['totalfcourse'] = $totalfcourse;
        $this->include_frontslide_js('promotedcourse');
        return $this->output->render_from_template("theme_academi/course_blocks", $template);
    }

    /**
     * Outputs contents for frontpage as configured in $CFG->frontpage or $CFG->frontpageloggedin
     *
     * @return string
     */
    public function frontpage() {
        global $CFG, $SITE;

        $output = '';
        $themeblocks = new \theme_academi\academi_blocks();
        $beforelayout = [FRONTPAGEPROMOTEDCOURSE, FRONTPAGESITEFEATURES, FRONTPAGEMARKETINGSPOT];
        $afterlayout = [FRONTPAGEJUMBOTRON];
        if (isloggedin() && !isguestuser() && isset($CFG->frontpageloggedin)) {
            $frontpagelayout = explode(",", $CFG->frontpageloggedin);
        } else {
            $frontpagelayout = explode(",", $CFG->frontpage);
        }
        $academifrontpagelayout = array_merge($beforelayout, $frontpagelayout, $afterlayout);
        foreach ($academifrontpagelayout as $a) {
            switch($a) {
                // Display the main part of the front page.
                case FRONTPAGENEWS:
                    if ($SITE->newsitems) {
                        // Print forums only when needed.
                        require_once($CFG->dirroot .'/mod/forum/lib.php');
                        if (($newsforum = forum_get_course_forum($SITE->id, 'news')) &&
                                ($forumcontents = $this->frontpage_news($newsforum))) {
                            $newsforumcm = get_fast_modinfo($SITE)->instances['forum'][$newsforum->id];
                            $output .= $this->frontpage_part('skipsitenews', 'site-news-forum',
                                $newsforumcm->get_formatted_name(), $forumcontents);
                        }
                    }
                    break;

                case FRONTPAGEENROLLEDCOURSELIST:
                    $mycourseshtml = $this->frontpage_my_courses();
                    if (!empty($mycourseshtml)) {
                        $output .= $this->frontpage_part('skipmycourses', 'frontpage-course-list',
                            get_string('mycourses'), $mycourseshtml);
                    }
                    break;

                case FRONTPAGEALLCOURSELIST:
                    $availablecourseshtml = $this->frontpage_available_courses();
                    $output .= $this->frontpage_part('skipavailablecourses', 'frontpage-available-course-list',
                        get_string('availablecourses'), $availablecourseshtml);
                    break;

                case FRONTPAGECATEGORYNAMES:
                    $output .= $this->frontpage_part('skipcategories', 'frontpage-category-names',
                        get_string('categories'), $this->frontpage_categories_list());
                    break;

                case FRONTPAGECATEGORYCOMBO:
                    $output .= $this->frontpage_part('skipcourses', 'frontpage-category-combo',
                        get_string('courses'), $this->frontpage_combo_list());
                    break;

                case FRONTPAGECOURSESEARCH:
                    $output .= $this->box($this->course_search_form(''), 'd-flex justify-content-center');
                    break;
                case FRONTPAGEPROMOTEDCOURSE:
                    $output .= $this->promoted_courses();
                    break;
                case FRONTPAGESITEFEATURES:
                    $output .= $themeblocks->sitefeatures();
                    break;
                case FRONTPAGEMARKETINGSPOT:
                    $output .= $themeblocks->marketingspot();
                    break;
                case FRONTPAGEJUMBOTRON:
                    $output .= $themeblocks->jumbotron();
                    break;
            }
            $output .= '<br />';
        }
        return $output;
    }

    /**
     * Renders the course category page with Academi catalogue framing.
     *
     * @param int|stdClass|\core_course_category $category Category to render.
     * @return string
     */
    public function course_category($category) {
        $coursecat = $this->get_course_category_for_catalogue($category);
        $coursecount = $coursecat->get_courses_count(['recursive' => true]);
        $categorycount = $coursecat->get_children_count();
        $cards = $this->get_course_catalogue_cards($coursecat);
        $programmecount = $categorycount ?: count($cards);
        $enrolledhealthworkers = $this->get_enrolled_health_worker_count($coursecat);
        $display = $this->get_course_catalogue_display();
        $perpage = $this->get_course_catalogue_perpage();
        $filter = $this->get_course_catalogue_filter();
        $cards = $this->apply_course_catalogue_filter($cards, $filter);
        $page = max(0, optional_param('page', 0, PARAM_INT));
        $totalcards = count($cards);
        $maxpage = $totalcards > 0 ? (int)floor(($totalcards - 1) / $perpage) : 0;
        $page = min($page, $maxpage);
        $pagedcards = array_slice($cards, $page * $perpage, $perpage);

        $output = html_writer::start_tag('div', ['class' => 'academi-course-index']);
        $output .= $this->course_catalogue_hero($coursecount, $programmecount, $enrolledhealthworkers);
        $output .= html_writer::start_tag('div', ['class' => 'course-catalogue-shell']);
        $output .= $this->course_catalogue_controls($totalcards, $display, $perpage, $filter);
        $output .= $this->course_catalogue_cards($pagedcards, $display);
        $output .= $this->course_catalogue_pagination($totalcards, $page, $perpage, $display, $filter);
        $output .= html_writer::end_tag('div');
        $output .= html_writer::end_tag('div');

        return $output;
    }

    /**
     * Render the catalogue hero area.
     *
     * @param int $coursecount Number of courses.
     * @param int $programmecount Number of programmes.
     * @param int $enrolledhealthworkers Number of enrolled health workers.
     * @return string
     */
    private function course_catalogue_hero(int $coursecount, int $programmecount, int $enrolledhealthworkers): string {
        $stats = html_writer::start_tag('dl', ['class' => 'course-index-hero__stats']);
        $stats .= html_writer::tag('div',
            html_writer::tag('dt', get_string('courses')) .
            html_writer::tag('dd', number_format($coursecount))
        );
        $stats .= html_writer::tag('div',
            html_writer::tag('dt', 'Programmes') .
            html_writer::tag('dd', number_format($programmecount))
        );
        $stats .= html_writer::tag('div',
            html_writer::tag('dt', 'Enrolled health workers') .
            html_writer::tag('dd', number_format($enrolledhealthworkers))
        );
        $stats .= html_writer::tag('div',
            html_writer::tag('dt', 'Accredited') .
            html_writer::tag('dd', 'CPD')
        );
        $stats .= html_writer::end_tag('dl');

        $search = html_writer::start_tag('form', [
            'class' => 'course-index-hero__search',
            'action' => new moodle_url('/course/search.php'),
            'method' => 'get',
        ]);
        $search .= html_writer::tag('span', '', ['class' => 'course-index-hero__searchicon', 'aria-hidden' => 'true']);
        $search .= html_writer::empty_tag('input', [
            'type' => 'search',
            'name' => 'search',
            'placeholder' => 'Search ' . number_format($coursecount) . ' courses, e.g. infection control',
            'aria-label' => get_string('searchcourses'),
        ]);
        $search .= html_writer::tag('button', get_string('search'), ['type' => 'submit']);
        $search .= html_writer::end_tag('form');

        $hero = html_writer::start_tag('div', ['class' => 'course-index-hero']);
        $hero .= html_writer::start_tag('div', ['class' => 'course-index-hero__inner']);
        $hero .= html_writer::tag('span', 'Ministry of Health - Malawi', ['class' => 'course-index-hero__eyebrow']);
        $hero .= html_writer::tag('h2', 'Continuous Professional Development Platform');
        $hero .= html_writer::tag('p',
            "Build the skills that keep Malawi's health system running - accredited, self-paced courses for every cadre, free for MoH staff.",
            ['class' => 'course-index-hero__intro']
        );
        $hero .= $search;
        $hero .= $stats;
        $hero .= html_writer::end_tag('div');
        $hero .= html_writer::tag('div', '', ['class' => 'course-index-hero__mark', 'aria-hidden' => 'true']);
        $hero .= html_writer::end_tag('div');

        return $hero;
    }

    /**
     * Render catalogue controls.
     *
     * @param int $cardcount Number of visible cards.
     * @param string $display Current display mode.
     * @param int $perpage Cards per page.
     * @return string
     */
    private function course_catalogue_controls(int $cardcount, string $display, int $perpage, string $filter): string {
        $pills = [
            'all' => 'All programmes',
            'popular' => 'Most popular',
            'recent' => 'Recently updated',
        ];
        $sortlabels = [
            'all' => 'A-Z',
            'popular' => 'Enrolments',
            'recent' => 'Last updated',
        ];

        $controls = html_writer::start_tag('div', ['class' => 'course-catalogue-toolbar']);
        $controls .= html_writer::start_tag('div', ['class' => 'course-catalogue-filters', 'aria-label' => 'Course filters']);
        foreach ($pills as $value => $label) {
            $controls .= $this->course_catalogue_filter_pill($label, $value, $filter, $display, $perpage);
        }
        $controls .= html_writer::end_tag('div');
        $controls .= html_writer::start_tag('div', ['class' => 'course-catalogue-actions']);
        $controls .= $this->course_catalogue_view_toggle($display, $perpage, $filter);
        $controls .= html_writer::tag('div',
            html_writer::tag('span', 'Sort:', ['class' => 'course-catalogue-sort__label']) . ' ' . $sortlabels[$filter],
            ['class' => 'course-catalogue-sort']
        );
        $controls .= html_writer::end_tag('div');
        $controls .= html_writer::end_tag('div');
        $controls .= html_writer::tag('h3', number_format($cardcount) . ' programmes', ['class' => 'course-catalogue-count']);

        return $controls;
    }

    /**
     * Render a single filter pill as a link.
     *
     * @param string $label Pill label.
     * @param string $value Filter value this pill represents.
     * @param string $current Currently-active filter value.
     * @param string $display Current display mode.
     * @param int $perpage Cards per page.
     * @return string
     */
    private function course_catalogue_filter_pill(string $label, string $value, string $current,
            string $display, int $perpage): string {
        $classes = ['catalogue-pill'];
        if ($value === $current) {
            $classes[] = 'active';
        }
        return html_writer::link($this->course_catalogue_url([
            'display' => $display,
            'perpage' => $perpage,
            'filter' => $value,
            'page' => 0,
        ]), $label, [
            'class' => implode(' ', $classes),
            'aria-current' => $value === $current ? 'true' : null,
        ]);
    }

    /**
     * Render card/list view toggle links.
     *
     * @param string $display Current display mode.
     * @param int $perpage Cards per page.
     * @return string
     */
    private function course_catalogue_view_toggle(string $display, int $perpage, string $filter): string {
        $output = html_writer::start_tag('div', [
            'class' => 'course-catalogue-view-toggle',
            'aria-label' => 'Display format',
        ]);

        foreach (['list' => 'List', 'card' => 'Cards'] as $mode => $label) {
            $classes = ['catalogue-view-option', 'catalogue-view-option--' . $mode];
            if ($display === $mode) {
                $classes[] = 'active';
            }
            $output .= html_writer::link($this->course_catalogue_url([
                'display' => $mode,
                'perpage' => $perpage,
                'filter' => $filter,
                'page' => 0,
            ]), $label, [
                'class' => implode(' ', $classes),
                'aria-current' => $display === $mode ? 'true' : null,
            ]);
        }

        $output .= html_writer::end_tag('div');
        return $output;
    }

    /**
     * Render catalogue cards.
     *
     * @param array $cards Card data.
     * @param string $display Current display mode.
     * @return string
     */
    private function course_catalogue_cards(array $cards, string $display): string {
        if (empty($cards)) {
            return html_writer::tag('div', get_string('nocourses'), ['class' => 'course-catalogue-empty']);
        }

        $output = html_writer::start_tag('div', ['class' => 'course-catalogue-list course-catalogue-list--' . $display]);
        foreach (array_values($cards) as $index => $card) {
            $variant = ($index % 6) + 1;
            $output .= html_writer::start_tag('article', ['class' => 'course-catalogue-card']);
            $output .= html_writer::tag('div',
                html_writer::tag('span', '', ['class' => 'catalogue-cover__icon', 'aria-hidden' => 'true']) .
                html_writer::tag('span', 'Cover', ['class' => 'catalogue-cover__label']),
                ['class' => 'catalogue-cover catalogue-cover--' . $variant]
            );
            $output .= html_writer::start_tag('div', ['class' => 'catalogue-card-body']);
            $output .= html_writer::start_tag('div', ['class' => 'catalogue-card-copy']);
            $output .= html_writer::tag('div', $this->course_catalogue_badges($card), ['class' => 'catalogue-card-badges']);
            $output .= html_writer::tag('h4',
                html_writer::link($card['url'], $card['name'])
            );
            $output .= html_writer::tag('p', $card['summary']);
            $output .= html_writer::tag('div', $this->course_catalogue_meta($card), ['class' => 'catalogue-card-meta']);
            $output .= html_writer::end_tag('div');
            $output .= html_writer::link($card['url'], 'View programme ' . html_writer::tag('span', '->', ['aria-hidden' => 'true']), [
                'class' => 'catalogue-card-action',
            ]);
            $output .= html_writer::end_tag('div');
            $output .= html_writer::end_tag('article');
        }
        $output .= html_writer::end_tag('div');

        return $output;
    }

    /**
     * Render pagination below the catalogue.
     *
     * @param int $total Total cards.
     * @param int $page Current page.
     * @param int $perpage Cards per page.
     * @param string $display Current display mode.
     * @return string
     */
    private function course_catalogue_pagination(int $total, int $page, int $perpage, string $display,
            string $filter): string {
        if ($total <= $perpage) {
            return '';
        }

        $paging = $this->paging_bar($total, $page, $perpage, $this->course_catalogue_url([
            'display' => $display,
            'perpage' => $perpage,
            'filter' => $filter,
        ]));

        return html_writer::tag('div', $paging, ['class' => 'course-catalogue-pagination']);
    }

    /**
     * Build card badges.
     *
     * @param array $card Card data.
     * @return string
     */
    private function course_catalogue_badges(array $card): string {
        $badges = '';
        if (!empty($card['certified'])) {
            $badges .= html_writer::tag('span', 'Certified', ['class' => 'badge-certified']);
        }
        $badges .= html_writer::tag('span', 'CPD', ['class' => 'badge-cpd']);
        return $badges;
    }

    /**
     * Build card metadata.
     *
     * @param array $card Card data.
     * @return string
     */
    private function course_catalogue_meta(array $card): string {
        $coursecount = (int)$card['coursecount'];
        $courselabel = $coursecount === 1 ? '1 course' : $coursecount . ' courses';
        $meta = html_writer::tag('span', $courselabel, ['class' => 'meta-courses']);
        $meta .= html_writer::tag('span', $card['duration'], ['class' => 'meta-duration']);
        $meta .= html_writer::tag('span', $card['level'], ['class' => 'meta-level']);
        $meta .= html_writer::tag('span',
            html_writer::tag('span', 'KM') .
            html_writer::tag('span', 'TZ') .
            html_writer::tag('span', 'RN') .
            html_writer::tag('span', '+5'),
            ['class' => 'meta-audience']
        );
        return $meta;
    }

    /**
     * Count distinct active enrolled users in the current catalogue scope.
     *
     * @param \core_course_category $coursecat Category to inspect.
     * @return int
     */
    private function get_enrolled_health_worker_count(\core_course_category $coursecat): int {
        global $DB;

        $now = round(time(), -2);
        $params = [
            'enrolenabled' => ENROL_INSTANCE_ENABLED,
            'useractive' => ENROL_USER_ACTIVE,
            'now1' => $now,
            'now2' => $now,
            'siteid' => SITEID,
        ];
        $categorywhere = '';

        if (!empty($coursecat->id)) {
            $categorywhere = ' AND (cc.id = :categoryid OR cc.path LIKE :categorypath)';
            $params['categoryid'] = $coursecat->id;
            $params['categorypath'] = $coursecat->path . '/%';
        }

        $sql = "SELECT COUNT(DISTINCT ue.userid)
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {course} c ON c.id = e.courseid
                  JOIN {course_categories} cc ON cc.id = c.category
                  JOIN {user} u ON u.id = ue.userid
                 WHERE e.status = :enrolenabled
                   AND ue.status = :useractive
                   AND ue.timestart < :now1
                   AND (ue.timeend = 0 OR ue.timeend > :now2)
                   AND c.id <> :siteid
                   AND c.visible = 1
                   AND cc.visible = 1
                   AND u.deleted = 0
                   AND u.suspended = 0
                   {$categorywhere}";

        return (int)$DB->count_records_sql($sql, $params);
    }

    /**
     * Build cards from child categories when present, otherwise direct courses.
     *
     * @param \core_course_category $coursecat Category to inspect.
     * @return array
     */
    private function get_course_catalogue_cards(\core_course_category $coursecat): array {
        $cards = [];
        $chelper = new \coursecat_helper();
        $children = $coursecat->get_children(['limit' => 0]);
        if (!empty($children)) {
            $stats = $this->get_category_stats_batch(array_map(fn($c) => $c->id, $children),
                array_map(fn($c) => $c->path, $children));
            foreach ($children as $child) {
                $summary = $this->course_catalogue_text($chelper->get_category_formatted_description($child));
                if ($summary === '') {
                    $summary = 'A focused learning pathway for Ministry of Health staff and health system partners.';
                }
                $stat = $stats[$child->id] ?? ['enrolcount' => 0, 'lastmodified' => 0];
                $cards[] = [
                    'name' => $child->get_formatted_name(),
                    'summary' => $summary,
                    'url' => new moodle_url('/course/index.php', ['categoryid' => $child->id]),
                    'coursecount' => $child->get_courses_count(['recursive' => true]),
                    'duration' => 'Self-paced',
                    'level' => 'All levels',
                    'certified' => true,
                    'popularity' => $stat['enrolcount'],
                    'timemodified' => $stat['lastmodified'],
                ];
            }
            return $cards;
        }

        $courses = $coursecat->get_courses([
            'recursive' => true,
            'summary' => true,
            'coursecontacts' => true,
            'limit' => 50,
        ]);
        $courseids = [];
        foreach ($courses as $course) {
            $courseids[] = $course->id;
        }
        $coursestats = $this->get_course_enrolment_counts($courseids);
        foreach ($courses as $course) {
            $summary = $this->course_catalogue_text($course->summary ?? '');
            if ($summary === '') {
                $summary = 'A practical CPD course for Malawi health workers.';
            }
            $cards[] = [
                'name' => $course->get_formatted_name(),
                'summary' => $summary,
                'url' => new moodle_url('/course/view.php', ['id' => $course->id]),
                'coursecount' => 1,
                'duration' => 'Self-paced',
                'level' => 'All levels',
                'certified' => !empty($course->visible),
                'popularity' => $coursestats[$course->id] ?? 0,
                'timemodified' => (int)($course->timemodified ?? 0),
            ];
        }

        return $cards;
    }

    /**
     * Compute enrolment counts and last-modified timestamps for each category in one pass.
     *
     * @param int[] $catids Category ids in render order.
     * @param string[] $paths Matching category paths (parallel to $catids).
     * @return array<int, array{enrolcount:int,lastmodified:int}>
     */
    private function get_category_stats_batch(array $catids, array $paths): array {
        global $DB;
        $result = [];
        foreach ($catids as $i => $catid) {
            $path = $paths[$i] ?? '';
            $params = [
                'enrolenabled' => ENROL_INSTANCE_ENABLED,
                'useractive' => ENROL_USER_ACTIVE,
                'siteid' => SITEID,
                'categoryid' => $catid,
                'categorypath' => $path . '/%',
            ];
            $modsql = "SELECT MAX(c.timemodified)
                         FROM {course} c
                         JOIN {course_categories} cc ON cc.id = c.category
                        WHERE c.id <> :siteid
                          AND c.visible = 1
                          AND (cc.id = :categoryid OR cc.path LIKE :categorypath)";
            $lastmodified = (int)$DB->get_field_sql($modsql, $params);

            $enrolsql = "SELECT COUNT(DISTINCT ue.userid)
                           FROM {user_enrolments} ue
                           JOIN {enrol} e ON e.id = ue.enrolid
                           JOIN {course} c ON c.id = e.courseid
                           JOIN {course_categories} cc ON cc.id = c.category
                          WHERE e.status = :enrolenabled
                            AND ue.status = :useractive
                            AND c.id <> :siteid
                            AND c.visible = 1
                            AND (cc.id = :categoryid OR cc.path LIKE :categorypath)";
            $enrolcount = (int)$DB->count_records_sql($enrolsql, $params);

            $result[$catid] = ['enrolcount' => $enrolcount, 'lastmodified' => $lastmodified];
        }
        return $result;
    }

    /**
     * Distinct active enrolment counts per course.
     *
     * @param int[] $courseids
     * @return array<int, int>
     */
    private function get_course_enrolment_counts(array $courseids): array {
        global $DB;
        if (empty($courseids)) {
            return [];
        }
        list($insql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
        $params['enrolenabled'] = ENROL_INSTANCE_ENABLED;
        $params['useractive'] = ENROL_USER_ACTIVE;

        $sql = "SELECT e.courseid AS courseid, COUNT(DISTINCT ue.userid) AS enrolcount
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                 WHERE e.status = :enrolenabled
                   AND ue.status = :useractive
                   AND e.courseid $insql
              GROUP BY e.courseid";
        $rows = $DB->get_records_sql($sql, $params);
        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row->courseid] = (int)$row->enrolcount;
        }
        return $result;
    }

    /**
     * Sort/filter cards according to the selected filter.
     *
     * @param array $cards Cards to process.
     * @param string $filter Filter key (all, popular, recent, certified).
     * @return array
     */
    private function apply_course_catalogue_filter(array $cards, string $filter): array {
        if ($filter === 'popular') {
            usort($cards, fn($a, $b) => ($b['popularity'] ?? 0) <=> ($a['popularity'] ?? 0)
                ?: strcasecmp($a['name'] ?? '', $b['name'] ?? ''));
            return $cards;
        }
        if ($filter === 'recent') {
            usort($cards, fn($a, $b) => ($b['timemodified'] ?? 0) <=> ($a['timemodified'] ?? 0)
                ?: strcasecmp($a['name'] ?? '', $b['name'] ?? ''));
            return $cards;
        }
        return $cards;
    }

    /**
     * Trim text for catalogue cards.
     *
     * @param string|null $text Raw text.
     * @return string
     */
    private function course_catalogue_text(?string $text): string {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($text ?? '')));
        if (strlen($text) > 170) {
            $text = rtrim(substr($text, 0, 167)) . '...';
        }
        return s($text);
    }

    /**
     * Get selected catalogue display mode.
     *
     * @return string
     */
    private function get_course_catalogue_display(): string {
        $display = optional_param('display', 'list', PARAM_ALPHA);
        return in_array($display, ['list', 'card'], true) ? $display : 'list';
    }

    /**
     * Get selected catalogue page size.
     *
     * @return int
     */
    private function get_course_catalogue_perpage(): int {
        $perpage = optional_param('perpage', 10, PARAM_INT);
        if ($perpage < 1) {
            return 10;
        }
        return min($perpage, 50);
    }

    /**
     * Get selected catalogue filter.
     *
     * @return string
     */
    private function get_course_catalogue_filter(): string {
        $filter = optional_param('filter', 'all', PARAM_ALPHA);
        return in_array($filter, ['all', 'popular', 'recent'], true) ? $filter : 'all';
    }

    /**
     * Build a catalogue URL preserving the current category page.
     *
     * @param array $params Query parameters.
     * @return moodle_url
     */
    private function course_catalogue_url(array $params): moodle_url {
        return new moodle_url($this->page->url, $params);
    }

    /**
     * Resolve a course category from the accepted course_category() argument shapes.
     *
     * @param int|stdClass|\core_course_category $category Category to resolve.
     * @return \core_course_category
     */
    private function get_course_category_for_catalogue($category) {
        if (empty($category)) {
            return \core_course_category::user_top();
        }
        if (is_object($category) && $category instanceof \core_course_category) {
            return $category;
        }
        return \core_course_category::get(is_object($category) ? $category->id : $category);
    }

    /**
     * Returns HTML to display a course category as a part of a tree
     *
     * This is an internal function, to display a particular category and all its contents.
     *
     * @param coursecat_helper $chelper various display options
     * @param core_course_category $coursecat
     * @param int $depth depth of this category in the current tree
     * @return string
     */
    protected function coursecat_category(\coursecat_helper $chelper, $coursecat, $depth) {
        // Open category tag.
        $classes = ['category'];
        if (empty($coursecat->visible)) {
            $classes[] = 'dimmed_category';
        }
        if ($chelper->get_subcat_depth() > 0 && $depth >= $chelper->get_subcat_depth()) {
            // Do not load content.
            $categorycontent = '';
            $classes[] = 'notloaded';
            if ($coursecat->get_children_count() ||
                    ($chelper->get_show_courses() >= self::COURSECAT_SHOW_COURSES_COLLAPSED && $coursecat->get_courses_count())) {
                $classes[] = 'with_children';
                $classes[] = 'collapsed';
            }
        } else {
            // Load category content.
            $categorycontent = $this->coursecat_category_content($chelper, $coursecat, $depth);
            $classes[] = 'loaded';
            if (!empty($categorycontent)) {
                $classes[] = 'with_children';
                // Category content loaded with children.
                $this->categoryexpandedonload = true;
            }
        }
        $combolistboxtype = (theme_academi_get_setting('comboListboxType') == 1) ? true : false;
        if ($combolistboxtype) {
            $classes[] = 'collapsed';
        }

        // Make sure JS file to expand category content is included.
        $this->coursecat_include_js();

        $content = html_writer::start_tag('div', [
            'class' => join(' ', $classes),
            'data-categoryid' => $coursecat->id,
            'data-depth' => $depth,
            'data-showcourses' => $chelper->get_show_courses(),
            'data-type' => self::COURSECAT_TYPE_CATEGORY,
        ]);

        // Category name.
        $categoryname = $coursecat->get_formatted_name();
        $categoryname = html_writer::link(new moodle_url('/course/index.php',
                ['categoryid' => $coursecat->id]),
                $categoryname);
        if ($chelper->get_show_courses() == self::COURSECAT_SHOW_COURSES_COUNT
                && ($coursescount = $coursecat->get_courses_count())) {
            $categoryname .= html_writer::tag('span', ' ('. $coursescount.')',
                    ['title' => get_string('numberofcourses'), 'class' => 'numberofcourse']);
        }
        $content .= html_writer::start_tag('div', ['class' => 'info']);

        $content .= html_writer::tag(($depth > 1) ? 'h4' : 'h3', $categoryname, ['class' => 'categoryname aabtn']);
        $content .= html_writer::end_tag('div'); // Info.

        // Add category content to the output.
        $content .= html_writer::tag('div', $categorycontent, ['class' => 'content']);

        $content .= html_writer::end_tag('div'); // Category.

        // Return the course category tree HTML.
        return $content;
    }

    /**
     * Returns HTML to display a tree of subcategories and courses in the given category
     *
     * @param coursecat_helper $chelper various display options
     * @param core_course_category $coursecat top category (this category's name and description will NOT be added to the tree)
     * @return string
     */
    protected function coursecat_tree(\coursecat_helper $chelper, $coursecat) {
        // Reset the category expanded flag for this course category tree first.
        $this->categoryexpandedonload = false;
        $categorycontent = $this->coursecat_category_content($chelper, $coursecat, 0);
        if (empty($categorycontent)) {
            return '';
        }

        // Start content generation.
        $content = '';
        $attributes = $chelper->get_and_erase_attributes('course_category_tree clearfix');
        $content .= html_writer::start_tag('div', $attributes);

        if ($coursecat->get_children_count()) {
            $classes = [
                'collapseexpand',
                'aabtn',
            ];

            // Check if the category content contains subcategories with children's content loaded.
            $combolistboxtype = (theme_academi_get_setting('comboListboxType') == 1) ? true : false;
            if ($this->categoryexpandedonload && !$combolistboxtype) {
                $classes[] = 'collapse-all';
                $linkname = get_string('collapseall');
            } else {
                $linkname = get_string('expandall');
            }

            // Only show the collapse/expand if there are children to expand.
            $content .= html_writer::start_tag('div', ['class' => 'collapsible-actions']);
            $content .= html_writer::link('#', $linkname, ['class' => implode(' ', $classes)]);
            $content .= html_writer::end_tag('div');
            $this->page->requires->strings_for_js(['collapseall', 'expandall'], 'moodle');
        }

        $content .= html_writer::tag('div', $categorycontent, ['class' => 'content']);

        $content .= html_writer::end_tag('div');

        return $content;
    }
}
