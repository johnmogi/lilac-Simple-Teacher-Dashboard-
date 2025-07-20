<?php
/**
 * Plugin Name: Simple Teacher Dashboard
 * Description: A simplified teacher dashboard showing only teacher's groups, students, and grades
 * Version: 2.0.0
 * Author: Your Name
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Simple_Teacher_Dashboard {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
    }
    
    public function init() {
        // Register shortcode
        add_shortcode('teacher_dashboard', array($this, 'render_dashboard'));
    }
    
    public function enqueue_scripts() {
        // Enqueue jQuery for interactive features
        wp_enqueue_script('jquery');
    }
    
    public function render_dashboard($atts) {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return $this->render_login_message();
        }
        
        $current_user = wp_get_current_user();
        $is_admin = current_user_can('manage_options');
        
        // Check if user has teacher role or is admin
        if (!$this->is_teacher($current_user) && !$is_admin) {
            return $this->render_no_permission_message();
        }
        
        // Determine which teacher to show dashboard for
        $selected_teacher_id = $current_user->ID;
        $selected_teacher = $current_user;
        
        // If admin and teacher_id parameter is provided, use that teacher
        if ($is_admin && isset($_GET['teacher_id']) && intval($_GET['teacher_id']) > 0) {
            $selected_teacher_id = intval($_GET['teacher_id']);
            $selected_teacher = get_user_by('ID', $selected_teacher_id);
            
            // Verify the selected user is actually a teacher
            if (!$selected_teacher || !$this->is_teacher($selected_teacher)) {
                $selected_teacher_id = $current_user->ID;
                $selected_teacher = $current_user;
            }
        }
        
        // Get teacher's groups
        $groups = $this->get_teacher_groups($selected_teacher_id);
        
        if (empty($groups) && !$is_admin) {
            return $this->render_no_groups_message($selected_teacher);
        }
        
        // Build dashboard HTML
        $html = '<div class="simple-teacher-dashboard">';
        
        // Add admin teacher selector if user is admin
        if ($is_admin) {
            $html .= $this->render_teacher_selector($current_user->ID, $selected_teacher_id);
        }
        
        $html .= '<h2>לוח בקרה למורה - ' . esc_html($selected_teacher->display_name) . '</h2>';
        
        // Add group selection interface or no groups message
        if (empty($groups)) {
            $html .= '<div class="no-groups-message">';
            $html .= '<p>למורה הזה אין קבוצות עם תלמידים.</p>';
            $html .= '</div>';
        } else {
            $html .= $this->render_group_selector($groups);
            
            // Add students display area
            $html .= '<div id="students-display" class="students-display-area">';
            $html .= '<p class="select-group-message">אנא בחר קבוצה כדי לראות את התלמידים.</p>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        
        // Add CSS and JavaScript
        $html .= $this->get_dashboard_css();
        $html .= $this->get_dashboard_javascript($groups);
        
        return $html;
    }
    
    /**
     * Check if user has teacher role
     */
    private function is_teacher($user) {
        // Check WordPress user roles first
        $teacher_roles = array(
            'school_teacher', 
            'instructor', 
            'Instructor', 
            'wdm_instructor',
            'stm_lms_instructor',
            'group_leader'
        );
        
        foreach ($teacher_roles as $role) {
            if (in_array($role, $user->roles)) {
                return true;
            }
        }
        
        // Check for group leader meta keys (LearnDash pattern)
        global $wpdb;
        $has_group_leader_meta = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) 
            FROM {$wpdb->usermeta}
            WHERE user_id = %d
            AND meta_key LIKE '%%group_leader%%'",
            $user->ID
        ));
        
        return $has_group_leader_meta > 0;
    }
    
    /**
     * Get all teachers for admin selection
     */
    private function get_all_teachers() {
        global $wpdb;
        
        // Get all potential teachers first
        $potential_teachers = $wpdb->get_results("
            SELECT DISTINCT
                u.ID as teacher_id,
                u.display_name as teacher_name,
                u.user_email as teacher_email
            FROM {$wpdb->users} u
            INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
            WHERE um.meta_key = 'wp_capabilities' 
            AND (
                um.meta_value LIKE '%group_leader%' 
                OR um.meta_value LIKE '%school_teacher%'
                OR um.meta_value LIKE '%instructor%'
                OR um.meta_value LIKE '%Instructor%'
                OR um.meta_value LIKE '%wdm_instructor%'
                OR um.meta_value LIKE '%stm_lms_instructor%'
            )
            ORDER BY u.display_name
        ");
        
        // Filter teachers who actually have groups with students
        $teachers_with_students = array();
        
        foreach ($potential_teachers as $teacher) {
            $groups = $this->get_teacher_groups($teacher->teacher_id);
            if (!empty($groups)) {
                $teachers_with_students[] = $teacher;
            }
        }
        
        return $teachers_with_students;
    }
    
    /**
     * Render teacher selector for admins
     */
    private function render_teacher_selector($current_teacher_id, $selected_teacher_id) {
        $teachers = $this->get_all_teachers();
        
        if (empty($teachers)) {
            return '';
        }
        
        $html = '<div class="admin-teacher-selector">';
        $html .= '<h3>בחירת מורה לצפייה (מנהל)</h3>';
        $html .= '<form method="get" class="teacher-selector-form">';
        
        // Preserve other GET parameters
        foreach ($_GET as $key => $value) {
            if ($key !== 'teacher_id') {
                $html .= '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '">';
            }
        }
        
        $html .= '<select name="teacher_id" id="teacher-selector" onchange="this.form.submit()">';
        
        foreach ($teachers as $teacher) {
            $selected = ($teacher->teacher_id == $selected_teacher_id) ? 'selected' : '';
            $html .= '<option value="' . esc_attr($teacher->teacher_id) . '" ' . $selected . '>';
            $html .= esc_html($teacher->teacher_name) . ' (' . esc_html($teacher->teacher_email) . ')';
            $html .= '</option>';
        }
        
        $html .= '</select>';
        $html .= '</form>';
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Get teacher's groups - show all groups for teachers with group_leader role
     */
    private function get_teacher_groups($teacher_id) {
        global $wpdb;
        
        $user = get_user_by('id', $teacher_id);
        
        // If user has group_leader role, show all groups with students
        if (in_array('group_leader', $user->roles)) {
            $query = "
                SELECT DISTINCT
                    g.ID as group_id,
                    g.post_title as group_name,
                    g.post_status,
                    COUNT(DISTINCT sm.user_id) as student_count
                FROM {$wpdb->posts} g
                LEFT JOIN {$wpdb->usermeta} sm ON sm.meta_key = CONCAT('learndash_group_users_', g.ID)
                WHERE g.post_type = 'groups'
                AND g.post_status = 'publish'
                GROUP BY g.ID, g.post_title, g.post_status
                HAVING student_count > 0
                ORDER BY g.post_title
            ";
            
            return $wpdb->get_results($query);
        }
        
        // For other teachers, use the original query to find their specific groups
        $query = "
            SELECT DISTINCT
                g.ID as group_id,
                g.post_title as group_name,
                g.post_status
            FROM {$wpdb->users} t
            JOIN {$wpdb->usermeta} glm ON t.ID = glm.user_id 
                AND glm.meta_key LIKE '%group_leader%'
            JOIN {$wpdb->posts} g ON g.ID = CAST(SUBSTRING_INDEX(glm.meta_key, '_', -1) AS UNSIGNED)
            WHERE t.ID = %d
            AND g.post_type = 'groups'
            AND g.post_status = 'publish'
            ORDER BY g.post_title
        ";
        
        return $wpdb->get_results($wpdb->prepare($query, $teacher_id));
    }
    
    /**
     * Render login message
     */
    private function render_login_message() {
        return '<div class="simple-teacher-dashboard login-message">
            <h3>לוח בקרה למורה</h3>
            <p>אנא התחבר כדי לראות את לוח הבקרה שלך.</p>
        </div>';
    }
    
    /**
     * Render no permission message
     */
    private function render_no_permission_message() {
        return '<div class="simple-teacher-dashboard no-permission">
            <h3>גישה נדחתה</h3>
            <p>אין לך הרשאה לראות את לוח הבקרה הזה. לוח בקרה זה זמין רק למורים ומדריכים.</p>
        </div>';
    }
    
    /**
     * Render no groups message
     */
    private function render_no_groups_message($user) {
        return '<div class="simple-teacher-dashboard no-groups">
            <h3>לוח בקרה למורה - ' . esc_html($user->display_name) . '</h3>
            <p>אתה עדיין לא מוקצה לאף קבוצה. אנא פנה למנהל המערכת.</p>
        </div>';
    }
    
    /**
     * Render group selector interface
     */
    private function render_group_selector($groups) {
        $html = '<div class="group-selector">';
        $html .= '<h3>בחר קבוצה</h3>';
        $html .= '<div class="group-buttons">';
        
        foreach ($groups as $group) {
            $html .= '<button class="group-btn" data-group-id="' . esc_attr($group->group_id) . '">';
            $html .= esc_html($group->group_name);
            $html .= '</button>';
        }
        
        $html .= '</div></div>';
        
        return $html;
    }
    
    /**
     * Get students in a specific group using the working pattern from QUERIES_SUCCESS.md
     */
    private function get_group_students($group_id) {
        global $wpdb;
        
        // Use the proven working query pattern from QUERIES_SUCCESS.md
        $query = "
            SELECT DISTINCT
                s.ID as student_id,
                s.display_name as student_name,
                s.user_login as student_login,
                s.user_email as student_email
            FROM {$wpdb->usermeta} sm
            JOIN {$wpdb->users} s ON s.ID = sm.user_id
            WHERE sm.meta_key = %s
            ORDER BY s.display_name
        ";
        
        $meta_key = 'learndash_group_users_' . $group_id;
        return $wpdb->get_results($wpdb->prepare($query, $meta_key));
    }
    
    private function get_student_quiz_stats($student_id) {
        global $wpdb;
        
        // Method 1: Try to get quiz scores from pro_quiz_statistic tables (most accurate)
        $pro_quiz_query = "
            SELECT 
                COUNT(ref.statistic_ref_id) as total_attempts,
                COUNT(DISTINCT ref.quiz_post_id) as unique_quizzes,
                COALESCE(ROUND(AVG(
                    CASE 
                        WHEN quiz_scores.total_questions > 0 THEN (quiz_scores.earned_points / quiz_scores.total_questions) * 100
                        ELSE 0
                    END
                ), 1), 0) as overall_success_rate,
                COALESCE(ROUND(AVG(
                    CASE 
                        WHEN quiz_scores.total_questions > 0 AND quiz_scores.earned_points > 0 
                        THEN (quiz_scores.earned_points / quiz_scores.total_questions) * 100
                        ELSE NULL
                    END
                ), 1), 0) as completed_only_rate
            FROM {$wpdb->prefix}learndash_pro_quiz_statistic_ref ref
            INNER JOIN (
                SELECT 
                    statistic_ref_id,
                    SUM(points) as earned_points,
                    COUNT(*) as total_questions
                FROM {$wpdb->prefix}learndash_pro_quiz_statistic
                GROUP BY statistic_ref_id
                HAVING COUNT(*) > 0
            ) quiz_scores ON ref.statistic_ref_id = quiz_scores.statistic_ref_id
            WHERE ref.user_id = %d
        ";
        
        $pro_quiz_result = $wpdb->get_row($wpdb->prepare($pro_quiz_query, $student_id), ARRAY_A);
        
        // If we have data from pro_quiz_statistic, use it
        if ($pro_quiz_result && $pro_quiz_result['total_attempts'] > 0) {
            return $pro_quiz_result;
        }
        
        // Method 2: Fallback to learndash_user_activity table
        $activity_scores = $wpdb->get_results($wpdb->prepare("
            SELECT activity_meta
            FROM {$wpdb->prefix}learndash_user_activity
            WHERE user_id = %d AND activity_type = 'quiz' AND activity_status = 1
        ", $student_id));
        
        if (count($activity_scores) > 0) {
            $total_percentage = 0;
            $valid_scores = 0;
            
            foreach ($activity_scores as $score) {
                $meta = maybe_unserialize($score->activity_meta);
                if (isset($meta['percentage']) && is_numeric($meta['percentage'])) {
                    $total_percentage += $meta['percentage'];
                    $valid_scores++;
                }
            }
            
            if ($valid_scores > 0) {
                $average = round($total_percentage / $valid_scores, 1);
                return array(
                    'total_attempts' => $valid_scores,
                    'unique_quizzes' => $valid_scores,
                    'overall_success_rate' => $average,
                    'completed_only_rate' => $average
                );
            }
        }
        
        // No quiz data at all - return zeros to indicate "אין נתונים"
        // This covers both students with no attempts and students with only empty attempts
        return array(
            'total_attempts' => 0,
            'unique_quizzes' => 0,
            'overall_success_rate' => 0,
            'completed_only_rate' => 0
        );
    }
    
    /**
     * Get student course completion status
     */
    private function get_student_course_completion($student_id) {
        global $wpdb;
        
        // Get course completion data from LearnDash
        $query = "
            SELECT 
                c.ID as course_id,
                c.post_title as course_name,
                CASE 
                    WHEN ua.activity_status = 1 THEN 'Completed'
                    WHEN ua.activity_status = 0 THEN 'In Progress'
                    ELSE 'Not Started'
                END as completion_status,
                ua.activity_completed as completion_date
            FROM {$wpdb->prefix}learndash_user_activity ua
            JOIN {$wpdb->posts} c ON c.ID = ua.post_id
            WHERE ua.user_id = %d
            AND ua.activity_type = 'course'
            AND c.post_type = 'sfwd-courses'
            ORDER BY ua.activity_updated DESC
            LIMIT 1
        ";
        
        $result = $wpdb->get_row($wpdb->prepare($query, $student_id), ARRAY_A);
        
        if (!$result) {
            return array(
                'course_name' => 'No Course Data',
                'completion_status' => 'Not Started',
                'completion_date' => null
            );
        }
        
        return array(
            'course_name' => $result['course_name'],
            'completion_status' => $result['completion_status'],
            'completion_date' => $result['completion_date']
        );
    }
    
    /**
     * Get dashboard CSS styles
     */
    private function get_dashboard_css() {
        return '<style>
            .simple-teacher-dashboard { 
                padding: 20px; 
                background: white;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                max-width: 1200px;
                margin: 20px auto;
                direction: rtl;
                text-align: right;
            }
            .admin-teacher-selector {
                background: #f8f9fa;
                border: 2px solid #2271b1;
                border-radius: 8px;
                padding: 20px;
                margin-bottom: 25px;
                text-align: center;
            }
            .admin-teacher-selector h3 {
                color: #2271b1;
                margin: 0 0 15px 0;
                font-size: 18px;
            }
            .teacher-selector-form {
                display: flex;
                justify-content: center;
                align-items: center;
            }
            #teacher-selector {
                padding: 10px 15px;
                border: 2px solid #ddd;
                border-radius: 5px;
                font-size: 16px;
                min-width: 300px;
                background: white;
                cursor: pointer;
                direction: ltr;
                text-align: left;
            }
            #teacher-selector:focus {
                outline: none;
                border-color: #2271b1;
                box-shadow: 0 0 5px rgba(34, 113, 177, 0.3);
            }
            .simple-teacher-dashboard h2 {
                color: #2271b1;
                margin-top: 0;
                padding-bottom: 15px;
                border-bottom: 2px solid #e0e0e0;
            }
            .group-selector {
                margin-bottom: 30px;
            }
            .group-selector h3 {
                color: #333;
                margin-bottom: 15px;
            }
            .group-buttons {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
            }
            .group-btn {
                padding: 12px 20px;
                background: #0073aa;
                color: white;
                border: none;
                border-radius: 5px;
                cursor: pointer;
                font-size: 14px;
                transition: all 0.3s ease;
            }
            .group-btn:hover {
                background: #005a87;
                transform: translateY(-2px);
            }
            .group-btn.active {
                background: #00a32a;
            }
            .students-display-area {
                min-height: 200px;
                padding: 20px;
                background: #f9f9f9;
                border-radius: 5px;
                border: 1px solid #ddd;
            }
            .select-group-message {
                text-align: center;
                color: #666;
                font-style: italic;
                padding: 40px;
                background: #f9f9f9;
                border-radius: 5px;
            }
            .no-groups-message {
                text-align: center;
                padding: 40px;
                background: #fff3cd;
                border: 1px solid #ffeaa7;
                border-radius: 5px;
                margin: 20px 0;
            }
            .no-groups-message p {
                color: #856404;
                font-size: 16px;
                margin: 0;
            }
            .students-table {
                margin-top: 20px;
            }
            .students-table h4 {
                color: #333;
                margin-bottom: 15px;
            }
            .students-table table { 
                width: 100%; 
                border-collapse: collapse;
                background: white;
                border-radius: 5px;
                overflow: hidden;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            .students-table th, .students-table td { 
                padding: 12px 15px;
                text-align: right;
                border-bottom: 1px solid #eee;
            }
            .students-table th {
                background-color: #f8f9fa;
                font-weight: 600;
                color: #333;
            }
            .students-table tr:hover {
                background-color: #f5f5f5;
            }
            .quiz-average {
                text-align: center;
                font-weight: 600;
            }
            .quiz-rate {
                padding: 4px 8px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: bold;
                color: white;
            }
            .quiz-rate.excellent {
                background-color: #00a32a;
            }
            .quiz-rate.good {
                background-color: #007cba;
            }
            .quiz-rate.average {
                background-color: #dba617;
            }
            .quiz-rate.needs-improvement {
                background-color: #d63638;
            }
            .no-data {
                color: #666;
                font-style: italic;
            }
            .login-message, .no-permission, .no-groups {
                padding: 30px;
                text-align: center;
                background: #f9f9f9;
                border: 1px solid #ddd;
                border-radius: 8px;
                margin: 20px auto;
                max-width: 600px;
            }
            .login-message h3, .no-permission h3, .no-groups h3 {
                color: #333;
                margin-bottom: 15px;
            }
            .loading {
                text-align: center;
                padding: 20px;
                color: #666;
            }
            .group-stats {
                background: #f8f9fa;
                padding: 15px;
                border-radius: 6px;
                margin-bottom: 20px;
                border-left: 4px solid #2271b1;
            }
            .group-stats h4 {
                margin: 0 0 10px 0;
                color: #2271b1;
            }
            .group-stats p {
                margin: 5px 0;
                color: #333;
            }
            .course-completion {
                text-align: center;
            }
            .course-name {
                font-weight: bold;
                margin-bottom: 4px;
                color: #333;
            }
            .completion-status {
                padding: 2px 8px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: bold;
                text-transform: uppercase;
            }
            .completion-status.completed {
                background: #d4edda;
                color: #155724;
            }
            .completion-status.in-progress {
                background: #fff3cd;
                color: #856404;
            }
            .completion-status.not-started {
                background: #f8d7da;
                color: #721c24;
            }
            @media (max-width: 768px) {
                .group-buttons {
                    flex-direction: column;
                }
                .group-btn {
                    width: 100%;
                }
                .students-table {
                    overflow-x: auto;
                }
            }
        </style>';
    }
    
    /**
     * Get dashboard JavaScript functionality
     */
    private function get_dashboard_javascript($groups) {
        // Prepare groups data for JavaScript with quiz statistics
        $groups_json = array();
        foreach ($groups as $group) {
            $students = $this->get_group_students($group->group_id);
            
            // Add quiz statistics and course completion for each student
            $students_with_stats = array();
            foreach ($students as $student) {
                $quiz_stats = $this->get_student_quiz_stats($student->student_id);
                $course_completion = $this->get_student_course_completion($student->student_id);
                $students_with_stats[] = array(
                    'student_id' => $student->student_id,
                    'student_name' => $student->student_name,
                    'student_login' => $student->student_login,
                    'student_email' => $student->student_email,
                    'quiz_stats' => $quiz_stats,
                    'course_completion' => $course_completion
                );
            }
            
            $groups_json[$group->group_id] = array(
                'name' => $group->group_name,
                'students' => $students_with_stats
            );
        }
        
        // Generate JavaScript with groups data
        $js_code = '
        <script>
        jQuery(document).ready(function($) {
            var groupsData = ' . wp_json_encode($groups_json) . ';
            
            $(".group-btn").click(function() {
                var groupId = $(this).data("group-id");
                var groupData = groupsData[groupId];
                
                $(".group-btn").removeClass("active");
                $(this).addClass("active");
                
                $("#students-display").html("<div class=\"loading\">Loading students...</div>");
                
                setTimeout(function() {
                    displayStudents(groupData.students);
                }, 300);
            });
            
            function displayStudents(students) {
                if (!students || students.length === 0) {
                    $("#students-display").html("<p>No students found in this group.</p>");
                    return;
                }
                
                // Calculate group average for students with quiz scores
                var studentsWithScores = students.filter(function(student) {
                    return student.quiz_stats.overall_success_rate > 0;
                });
                
                var groupAverage = 0;
                if (studentsWithScores.length > 0) {
                    var totalScore = studentsWithScores.reduce(function(sum, student) {
                        return sum + parseFloat(student.quiz_stats.overall_success_rate);
                    }, 0);
                    groupAverage = (totalScore / studentsWithScores.length).toFixed(1);
                }
                
                var html = "<div class=\"group-stats\">";
                html += "<h4>סטטיסטיקת הקבוצה</h4>";
                html += "<p><strong>תלמידים עם ציוני בחינות:</strong> " + studentsWithScores.length + " מתוך " + students.length + "</p>";
                if (groupAverage > 0) {
                    html += "<p><strong>ממוצע הקבוצה:</strong> " + formatQuizAverage(groupAverage) + "</p>";
                }
                html += "</div>";
                
                html += "<table class=\"students-table\">";
                html += "<thead><tr><th>שם התלמיד</th><th>אימייל</th><th>השלמת קורס</th><th>ממוצע כל הבחינות</th><th>ממוצע בחינות שהושלמו</th></tr></thead>";
                html += "<tbody>";
                
                students.forEach(function(student) {
                    html += "<tr>";
                    html += "<td>" + student.student_name + "</td>";
                    html += "<td>" + student.student_email + "</td>";
                    html += "<td>" + formatCourseCompletion(student.course_completion) + "</td>";
                    html += "<td>" + formatQuizAverage(student.quiz_stats.overall_success_rate) + "</td>";
                    html += "<td>" + formatQuizAverage(student.quiz_stats.completed_only_rate) + "</td>";
                    html += "</tr>";
                });
                
                html += "</tbody></table>";
                $("#students-display").html(html);
            }
            
            function formatQuizAverage(successRate) {
                if (!successRate || successRate === 0) {
                    return "<span class=\"no-data\">אין נתונים</span>";
                }
                
                var rate = parseFloat(successRate);
                var className;
                
                if (rate >= 80) {
                    className = "excellent";
                } else if (rate >= 70) {
                    className = "good";
                } else if (rate >= 60) {
                    className = "average";
                } else {
                    className = "needs-improvement";
                }
                
                return "<span class=\"quiz-rate " + className + "\">" + rate.toFixed(1) + "%</span>";
            }
            
            function formatCourseCompletion(courseData) {
                if (!courseData || !courseData.course_name) {
                    return "<span class=\"no-data\">אין נתוני קורס</span>";
                }
                
                var statusClass = "";
                var statusText = "";
                switch(courseData.completion_status) {
                    case "Completed":
                        statusClass = "completed";
                        statusText = "הושלם";
                        break;
                    case "In Progress":
                        statusClass = "in-progress";
                        statusText = "בתהליך";
                        break;
                    default:
                        statusClass = "not-started";
                        statusText = "לא התחיל";
                }
                
                return "<div class=\"course-completion\">" +
                       "<div class=\"course-name\">" + courseData.course_name + "</div>" +
                       "<span class=\"completion-status " + statusClass + "\">" + statusText + "</span>" +
                       "</div>";
            }
        });
        </script>';
        
        return $js_code;
    }
}

// Initialize the plugin
new Simple_Teacher_Dashboard();
