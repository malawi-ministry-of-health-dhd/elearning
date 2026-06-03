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
        if ($this->is_programme_detail_view($coursecat)) {
            return $this->programme_detail($coursecat);
        }
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
     * Should this category render as a programme detail page (modules + progress)
     * rather than as a catalogue of sub-programmes?
     *
     * @param \core_course_category $coursecat
     * @return bool
     */
    private function is_programme_detail_view(\core_course_category $coursecat): bool {
        if (empty($coursecat->id)) {
            return false;
        }
        if ($coursecat->get_children_count() > 0) {
            return false;
        }
        return $coursecat->get_courses_count(['recursive' => false]) > 0
            || $coursecat->get_courses_count(['recursive' => true]) > 0;
    }

    /**
     * Render the programme (module list) detail page for a leaf category.
     *
     * @param \core_course_category $coursecat
     * @return string
     */
    private function programme_detail(\core_course_category $coursecat): string {
        global $USER, $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $courses = $coursecat->get_courses([
            'recursive' => true,
            'summary' => true,
            'coursecontacts' => false,
            'limit' => 200,
        ]);
        $courselist = [];
        $courseids = [];
        foreach ($courses as $course) {
            $courselist[] = $course;
            $courseids[] = (int)$course->id;
        }

        $userid = (int)($USER->id ?? 0);
        $progress = $this->compute_programme_progress($userid, $courseids);
        $instructorcount = $this->get_programme_instructor_count($coursecat);

        $display = $this->get_course_catalogue_display();
        $perpage = $this->get_course_catalogue_perpage();
        $page = max(0, optional_param('page', 0, PARAM_INT));
        $totalcourses = count($courselist);
        $maxpage = $totalcourses > 0 ? (int)floor(($totalcourses - 1) / $perpage) : 0;
        $page = min($page, $maxpage);
        $paged = array_slice($courselist, $page * $perpage, $perpage);

        $output = html_writer::start_tag('div', ['class' => 'academi-course-index academi-programme-detail']);
        $output .= $this->programme_hero($coursecat, $totalcourses, $progress, $instructorcount);
        $output .= html_writer::start_tag('div', ['class' => 'course-catalogue-shell']);
        $output .= $this->programme_toolbar($coursecat, $display, $perpage);

        $continue = $this->find_programme_continue($progress);
        if ($continue && !empty($courselist)) {
            $bycourse = [];
            foreach ($courselist as $c) {
                $bycourse[(int)$c->id] = $c;
            }
            if (isset($bycourse[$continue['courseid']])) {
                $output .= $this->programme_continue_card($bycourse[$continue['courseid']], $continue);
            }
        }

        $output .= html_writer::tag('h3',
            html_writer::tag('span', 'Courses in this programme',
                ['class' => 'programme-courses-heading__label']) . ' ' .
            html_writer::tag('span', number_format($totalcourses),
                ['class' => 'programme-courses-heading__count']),
            ['class' => 'programme-courses-heading']
        );

        $output .= $this->programme_course_list($paged, $progress['percourse'] ?? [], $display, $page * $perpage);
        $output .= $this->course_catalogue_pagination($totalcourses, $page, $perpage, $display, 'all');

        $output .= html_writer::end_tag('div');
        $output .= html_writer::end_tag('div');
        return $output;
    }

    /**
     * Render the programme detail hero block.
     */
    private function programme_hero(\core_course_category $coursecat, int $coursecount,
            array $progress, int $instructorcount): string {
        $name = $coursecat->get_formatted_name();
        $chelper = new \coursecat_helper();
        $desc = $this->course_catalogue_text($chelper->get_category_formatted_description($coursecat));
        if ($desc === '') {
            $desc = 'A focused learning pathway for Ministry of Health staff and health system partners.';
        }

        $crumb = html_writer::start_tag('nav', ['class' => 'programme-hero__crumb', 'aria-label' => 'Breadcrumb']);
        $crumb .= html_writer::link(new moodle_url('/course/index.php'), 'All programmes');
        $crumb .= html_writer::tag('span', '>',
            ['class' => 'programme-hero__crumbsep', 'aria-hidden' => 'true']);
        $crumb .= html_writer::tag('span', $name, ['class' => 'programme-hero__crumbactive']);
        $crumb .= html_writer::end_tag('nav');

        $badges = html_writer::start_tag('div', ['class' => 'programme-hero__badges']);
        $badges .= html_writer::tag('span', 'Certified', ['class' => 'badge-certified']);
        $badges .= html_writer::tag('span', 'CPD accredited', ['class' => 'badge-cpd']);
        if (($progress['inprogress'] ?? 0) + ($progress['completed'] ?? 0) > 0) {
            $badges .= html_writer::tag('span', 'Most popular', ['class' => 'badge-popular']);
        }
        $badges .= html_writer::end_tag('div');

        $meta = html_writer::start_tag('div', ['class' => 'programme-hero__meta']);
        $meta .= html_writer::tag('span', $coursecount . ' courses', ['class' => 'meta-courses']);
        $meta .= html_writer::tag('span', 'Self-paced', ['class' => 'meta-duration']);
        $meta .= html_writer::tag('span', 'All levels', ['class' => 'meta-level']);
        $meta .= html_writer::tag('span',
            html_writer::tag('span', 'KM') .
            html_writer::tag('span', 'AC') .
            html_writer::tag('span', 'TZ') .
            html_writer::tag('span', 'RN') .
            html_writer::tag('span', '+5'),
            ['class' => 'meta-audience']
        );
        if ($instructorcount > 0) {
            $meta .= html_writer::tag('span', $instructorcount . ' instructors',
                ['class' => 'meta-instructors']);
        }
        $meta .= html_writer::end_tag('div');

        $left = html_writer::start_tag('div', ['class' => 'programme-hero__main']);
        $left .= $crumb;
        $left .= $badges;
        $left .= html_writer::tag('h2', $name, ['class' => 'programme-hero__title']);
        $left .= html_writer::tag('p', $desc, ['class' => 'programme-hero__desc']);
        $left .= $meta;
        $left .= html_writer::end_tag('div');

        $right = $this->programme_progress_card($progress);

        $hero = html_writer::start_tag('div', ['class' => 'programme-hero']);
        $hero .= html_writer::start_tag('div', ['class' => 'programme-hero__inner']);
        $hero .= $left;
        $hero .= $right;
        $hero .= html_writer::end_tag('div');
        $hero .= html_writer::end_tag('div');
        return $hero;
    }

    /**
     * Render the right-side "Your progress" card on the hero.
     */
    private function programme_progress_card(array $progress): string {
        $total = max(1, (int)($progress['total'] ?? 0));
        $completed = (int)($progress['completed'] ?? 0);
        $inprogress = (int)($progress['inprogress'] ?? 0);
        $percent = (int)round(($completed / $total) * 100);

        $card = html_writer::start_tag('aside', ['class' => 'programme-progress-card']);
        $card .= html_writer::tag('span', 'YOUR PROGRESS',
            ['class' => 'programme-progress-card__label']);
        $card .= html_writer::tag('div',
            html_writer::tag('strong', $completed,
                ['class' => 'programme-progress-card__count']) .
            html_writer::tag('span', ' of ' . (int)($progress['total'] ?? 0) . ' courses complete',
                ['class' => 'programme-progress-card__caption']),
            ['class' => 'programme-progress-card__headline']
        );
        $card .= html_writer::tag('div',
            html_writer::tag('div', '', [
                'class' => 'programme-progress-card__barfill',
                'style' => "width: {$percent}%;",
            ]),
            ['class' => 'programme-progress-card__bar', 'role' => 'progressbar',
                'aria-valuenow' => $percent, 'aria-valuemin' => 0, 'aria-valuemax' => 100]
        );
        $stats = html_writer::tag('span', $percent . '% complete',
            ['class' => 'programme-progress-card__percent']);
        if ($inprogress > 0) {
            $stats .= html_writer::tag('span', $inprogress . ' in progress',
                ['class' => 'programme-progress-card__inprogress']);
        }
        $card .= html_writer::tag('div', $stats,
            ['class' => 'programme-progress-card__stats']);

        $continue = $this->find_programme_continue($progress);
        $cta = null;
        if ($continue) {
            $cta = ['url' => new moodle_url('/course/view.php', ['id' => $continue['courseid']]),
                    'label' => 'Resume learning'];
        } else {
            foreach (($progress['percourse'] ?? []) as $cid => $entry) {
                if ($entry['status'] === 'notstarted') {
                    $cta = ['url' => new moodle_url('/course/view.php', ['id' => $cid]),
                            'label' => 'Start learning'];
                    break;
                }
            }
        }
        if ($cta) {
            $card .= html_writer::link($cta['url'],
                html_writer::tag('span', '▶', ['class' => 'programme-progress-card__btnicon',
                    'aria-hidden' => 'true']) . ' ' . $cta['label'],
                ['class' => 'programme-progress-card__btn']);
        }

        $card .= html_writer::end_tag('aside');
        return $card;
    }

    /**
     * Render the toolbar above the module list.
     */
    private function programme_toolbar(\core_course_category $coursecat, string $display, int $perpage): string {
        $toolbar = html_writer::start_tag('div', ['class' => 'programme-toolbar']);
        $toolbar .= $this->programme_sibling_select($coursecat);
        $toolbar .= html_writer::start_tag('form', [
            'class' => 'programme-toolbar__search',
            'action' => new moodle_url('/course/search.php'),
            'method' => 'get',
        ]);
        $toolbar .= html_writer::tag('span', '', [
            'class' => 'programme-toolbar__searchicon',
            'aria-hidden' => 'true',
        ]);
        $toolbar .= html_writer::empty_tag('input', [
            'type' => 'search',
            'name' => 'q',
            'placeholder' => 'Search courses in this programme',
            'aria-label' => 'Search courses in this programme',
        ]);
        $toolbar .= html_writer::end_tag('form');
        $toolbar .= html_writer::start_tag('div', ['class' => 'programme-toolbar__actions']);
        $toolbar .= html_writer::tag('div',
            html_writer::tag('span', 'Sort:', ['class' => 'programme-toolbar__sortlabel']) .
            ' Standard order',
            ['class' => 'programme-toolbar__sort']
        );
        $toolbar .= $this->course_catalogue_view_toggle($display, $perpage, 'all');
        $toolbar .= html_writer::end_tag('div');
        $toolbar .= html_writer::end_tag('div');
        return $toolbar;
    }

    /**
     * Build a category selector (jumps to a sibling programme).
     */
    private function programme_sibling_select(\core_course_category $coursecat): string {
        $siblings = [$coursecat->id => $coursecat->get_formatted_name()];
        if (!empty($coursecat->parent)) {
            $parent = \core_course_category::get($coursecat->parent, IGNORE_MISSING);
            if ($parent) {
                $siblings = [];
                foreach ($parent->get_children(['limit' => 0]) as $sib) {
                    $siblings[$sib->id] = $sib->get_formatted_name();
                }
            }
        }

        $options = '';
        foreach ($siblings as $sid => $sname) {
            $url = new moodle_url('/course/index.php', ['categoryid' => $sid]);
            $attrs = ['value' => $url->out(false)];
            if ((int)$sid === (int)$coursecat->id) {
                $attrs['selected'] = 'selected';
            }
            $options .= html_writer::tag('option', $sname, $attrs);
        }

        return html_writer::tag('select', $options, [
            'class' => 'programme-toolbar__select',
            'onchange' => 'if(this.value){window.location.href=this.value;}',
            'aria-label' => 'Switch programme',
        ]);
    }

    /**
     * Render the "Continue where you left off" banner.
     */
    private function programme_continue_card($course, array $continue): string {
        $name = method_exists($course, 'get_formatted_name')
            ? $course->get_formatted_name()
            : format_string($course->fullname);
        $percent = (int)round((float)($continue['percent'] ?? 0));
        $url = new moodle_url('/course/view.php', ['id' => (int)$continue['courseid']]);

        $card = html_writer::start_tag('div', ['class' => 'programme-continue']);
        $card .= html_writer::tag('div', '', [
            'class' => 'programme-continue__icon',
            'aria-hidden' => 'true',
        ]);
        $card .= html_writer::start_tag('div', ['class' => 'programme-continue__body']);
        $card .= html_writer::tag('span', 'CONTINUE WHERE YOU LEFT OFF',
            ['class' => 'programme-continue__eyebrow']);
        $card .= html_writer::tag('h4', $name, ['class' => 'programme-continue__title']);
        $card .= html_writer::end_tag('div');
        $card .= html_writer::tag('div',
            html_writer::tag('div', '', [
                'class' => 'programme-continue__barfill',
                'style' => "width: {$percent}%;",
            ]),
            ['class' => 'programme-continue__bar', 'role' => 'progressbar',
                'aria-valuenow' => $percent, 'aria-valuemin' => 0, 'aria-valuemax' => 100]
        );
        $card .= html_writer::tag('span', $percent . '%',
            ['class' => 'programme-continue__percent']);
        $card .= html_writer::link($url,
            html_writer::tag('span', '▶',
                ['class' => 'programme-continue__btnicon', 'aria-hidden' => 'true']) . ' Resume',
            ['class' => 'programme-continue__btn']);
        $card .= html_writer::end_tag('div');
        return $card;
    }

    /**
     * Render the list of module cards.
     */
    private function programme_course_list(array $courses, array $percourse, string $display, int $offset): string {
        if (empty($courses)) {
            return html_writer::tag('div', get_string('nocourses'),
                ['class' => 'course-catalogue-empty']);
        }
        $output = html_writer::start_tag('div',
            ['class' => 'programme-course-list programme-course-list--' . $display]);
        $i = 0;
        foreach ($courses as $course) {
            $position = $offset + $i + 1;
            $entry = $percourse[(int)$course->id] ?? ['status' => 'notstarted', 'percent' => 0];
            $output .= $this->programme_course_card($course, $entry, $position);
            $i++;
        }
        $output .= html_writer::end_tag('div');
        return $output;
    }

    /**
     * Render a single module/course card.
     */
    private function programme_course_card($course, array $entry, int $position): string {
        $variant = (($position - 1) % 6) + 1;
        $name = method_exists($course, 'get_formatted_name')
            ? $course->get_formatted_name()
            : format_string($course->fullname);
        $summary = $this->course_catalogue_text($course->summary ?? '');
        if ($summary === '') {
            $summary = 'A practical CPD course for Malawi health workers.';
        }
        $url = new moodle_url('/course/view.php', ['id' => (int)$course->id]);
        $timecreated = (int)($course->timecreated ?? 0);
        $isnew = $timecreated > 0 && (time() - $timecreated) < (30 * DAYSECS);
        $lessoncount = $this->count_course_modules((int)$course->id);
        $status = $entry['status'] ?? 'notstarted';
        $percent = (int)round((float)($entry['percent'] ?? 0));

        $output = html_writer::start_tag('article',
            ['class' => 'programme-course-card programme-course-card--' . $status]);

        $cover = html_writer::start_tag('div',
            ['class' => 'programme-course-card__cover programme-course-card__cover--' . $variant]);
        $cover .= html_writer::tag('span', 'Standard ' . $position,
            ['class' => 'programme-course-card__coverlabel']);
        $cover .= html_writer::tag('span', '', [
            'class' => 'programme-course-card__covericon',
            'aria-hidden' => 'true',
        ]);
        $cover .= html_writer::tag('span', 'COVER',
            ['class' => 'programme-course-card__coverfoot']);
        $cover .= html_writer::end_tag('div');
        $output .= $cover;

        $body = html_writer::start_tag('div', ['class' => 'programme-course-card__body']);

        $badges = html_writer::start_tag('div', ['class' => 'programme-course-card__badges']);
        if ($status !== 'notstarted') {
            $badges .= html_writer::tag('span', 'Certified', ['class' => 'badge-certified']);
        }
        $badges .= html_writer::tag('span', 'CPD', ['class' => 'badge-cpd']);
        if ($isnew) {
            $badges .= html_writer::tag('span', 'New', ['class' => 'badge-new']);
        }
        $badges .= html_writer::end_tag('div');
        $body .= $badges;

        $body .= html_writer::tag('h4', html_writer::link($url, $name),
            ['class' => 'programme-course-card__title']);
        $body .= html_writer::tag('p', $summary,
            ['class' => 'programme-course-card__desc']);

        $meta = html_writer::start_tag('div', ['class' => 'programme-course-card__meta']);
        $lessonlabel = $lessoncount === 1 ? '1 lesson' : $lessoncount . ' lessons';
        $meta .= html_writer::tag('span', $lessonlabel, ['class' => 'meta-lessons']);
        $meta .= html_writer::tag('span', 'Self-paced', ['class' => 'meta-duration']);
        $meta .= html_writer::tag('span', 'All levels', ['class' => 'meta-level']);
        $meta .= html_writer::tag('span',
            html_writer::tag('span', 'KM') .
            html_writer::tag('span', 'AC') .
            html_writer::tag('span', 'TZ'),
            ['class' => 'meta-audience']
        );
        $meta .= html_writer::end_tag('div');
        $body .= $meta;

        if ($status !== 'notstarted') {
            $progress = html_writer::start_tag('div',
                ['class' => 'programme-course-card__progress']);
            $progress .= html_writer::tag('div',
                html_writer::tag('div', '', [
                    'class' => 'programme-course-card__progressfill',
                    'style' => "width: {$percent}%;",
                ]),
                ['class' => 'programme-course-card__progressbar', 'role' => 'progressbar',
                    'aria-valuenow' => $percent, 'aria-valuemin' => 0, 'aria-valuemax' => 100]
            );
            $progress .= html_writer::tag('span', $percent . '%',
                ['class' => 'programme-course-card__progresspct']);
            $progress .= html_writer::end_tag('div');
            $body .= $progress;
        }

        $body .= html_writer::end_tag('div');
        $output .= $body;

        $action = html_writer::start_tag('div', ['class' => 'programme-course-card__action']);
        if ($status === 'completed') {
            $action .= html_writer::tag('span',
                html_writer::tag('span', '✓ ', ['aria-hidden' => 'true']) . 'Completed',
                ['class' => 'programme-course-card__completed']);
        } else if ($status === 'inprogress') {
            $action .= html_writer::link($url,
                html_writer::tag('span', '▶ ', ['aria-hidden' => 'true']) . 'Continue',
                ['class' => 'programme-course-card__continue']);
        } else {
            $action .= html_writer::link($url,
                'Start course ' . html_writer::tag('span', '→', ['aria-hidden' => 'true']),
                ['class' => 'programme-course-card__start']);
        }
        $action .= html_writer::end_tag('div');
        $output .= $action;

        $output .= html_writer::end_tag('article');
        return $output;
    }

    /**
     * Compute per-course and aggregate progress for the current user.
     *
     * @param int $userid
     * @param int[] $courseids
     * @return array
     */
    private function compute_programme_progress(int $userid, array $courseids): array {
        global $DB;
        $result = [
            'total' => count($courseids),
            'completed' => 0,
            'inprogress' => 0,
            'notstarted' => 0,
            'percourse' => [],
        ];
        if (empty($courseids)) {
            return $result;
        }
        if ($userid <= 0 || isguestuser($userid)) {
            $result['notstarted'] = count($courseids);
            foreach ($courseids as $cid) {
                $result['percourse'][$cid] = ['status' => 'notstarted', 'percent' => 0, 'lastaccess' => 0];
            }
            return $result;
        }

        list($insql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'cid');
        $params['userid'] = $userid;

        $enrolledset = [];
        $enrolsql = "SELECT DISTINCT e.courseid
                       FROM {user_enrolments} ue
                       JOIN {enrol} e ON e.id = ue.enrolid
                      WHERE ue.userid = :userid
                        AND e.courseid $insql";
        foreach ($DB->get_fieldset_sql($enrolsql, $params) as $cid) {
            $enrolledset[(int)$cid] = true;
        }

        $completions = $DB->get_records_sql(
            "SELECT cc.course, cc.timecompleted
               FROM {course_completions} cc
              WHERE cc.userid = :userid AND cc.course $insql",
            $params
        );

        $lastaccess = [];
        foreach ($DB->get_records_sql(
            "SELECT courseid, timeaccess FROM {user_lastaccess}
              WHERE userid = :userid AND courseid $insql", $params) as $row) {
            $lastaccess[(int)$row->courseid] = (int)$row->timeaccess;
        }

        foreach ($courseids as $cid) {
            $cid = (int)$cid;
            $entry = ['status' => 'notstarted', 'percent' => 0,
                      'lastaccess' => $lastaccess[$cid] ?? 0];
            if (isset($completions[$cid]) && !empty($completions[$cid]->timecompleted)) {
                $entry['status'] = 'completed';
                $entry['percent'] = 100;
                $result['completed']++;
            } else if (isset($enrolledset[$cid])) {
                $entry['status'] = 'inprogress';
                $entry['percent'] = $this->safe_course_progress_percent($cid, $userid);
                $result['inprogress']++;
            } else {
                $result['notstarted']++;
            }
            $result['percourse'][$cid] = $entry;
        }
        return $result;
    }

    /**
     * Calculate course completion percentage with safe fallbacks.
     */
    private function safe_course_progress_percent(int $courseid, int $userid): int {
        try {
            $course = get_course($courseid);
            $percent = \core_completion\progress::get_course_progress_percentage($course, $userid);
            if ($percent !== null) {
                return (int)round((float)$percent);
            }
        } catch (\Throwable $e) {
            // Swallow — fall through.
        }
        return 0;
    }

    /**
     * Find the most recently accessed in-progress course.
     */
    private function find_programme_continue(array $progress): ?array {
        $best = null;
        foreach (($progress['percourse'] ?? []) as $cid => $entry) {
            if (($entry['status'] ?? '') !== 'inprogress') {
                continue;
            }
            if ($best === null || ($entry['lastaccess'] ?? 0) > ($best['lastaccess'] ?? 0)) {
                $best = ['courseid' => (int)$cid] + $entry;
            }
        }
        return $best;
    }

    /**
     * Count teachers across visible courses in this category (and descendants).
     */
    private function get_programme_instructor_count(\core_course_category $coursecat): int {
        global $DB;
        $params = [
            'siteid' => SITEID,
            'categoryid' => $coursecat->id,
            'categorypath' => $coursecat->path . '/%',
            'contextlevel' => CONTEXT_COURSE,
        ];
        $sql = "SELECT COUNT(DISTINCT ra.userid)
                  FROM {role_assignments} ra
                  JOIN {context} ctx ON ctx.id = ra.contextid AND ctx.contextlevel = :contextlevel
                  JOIN {course} c ON c.id = ctx.instanceid
                  JOIN {course_categories} cc ON cc.id = c.category
                  JOIN {role} r ON r.id = ra.roleid
                 WHERE c.visible = 1
                   AND c.id <> :siteid
                   AND (cc.id = :categoryid OR cc.path LIKE :categorypath)
                   AND r.archetype IN ('editingteacher', 'teacher')";
        return (int)$DB->count_records_sql($sql, $params);
    }

    /**
     * Count visible activity modules within a course.
     */
    private function count_course_modules(int $courseid): int {
        try {
            $modinfo = get_fast_modinfo($courseid);
            $count = 0;
            foreach ($modinfo->get_cms() as $cm) {
                if ($cm->uservisible && !$cm->deletioninprogress) {
                    $count++;
                }
            }
            return $count;
        } catch (\Throwable $e) {
            return 0;
        }
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
