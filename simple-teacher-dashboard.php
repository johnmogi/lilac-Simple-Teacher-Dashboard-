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
        
        // Check if user has teacher role
        if (!$this->is_teacher($current_user)) {
            return $this->render_no_permission_message();
        }
        
        // Get teacher's groups
        $groups = $this->get_teacher_groups($current_user->ID);
        
        if (empty($groups)) {
            return $this->render_no_groups_message($current_user);
        }
        
        // Build dashboard HTML
        $html = '<div class="simple-teacher-dashboard">';
        $html .= '<h2>Teacher Dashboard - ' . esc_html($current_user->display_name) . '</h2>';
        
        // Add group selection interface
        $html .= $this->render_group_selector($groups);
        
        // Add students display area
        $html .= '<div id="students-display" class="students-display-area">';
        $html .= '<p class="select-group-message">Please select a group to view students.</p>';
        $html .= '</div>';
        
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
        $teacher_roles = array('school_teacher', 'instructor', 'Instructor', 'wdm_instructor');
        
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
     * Get teacher's groups using the working query pattern from QUERIES_SUCCESS.md
     */
    private function get_teacher_groups($teacher_id) {
        global $wpdb;
        
        // Use the proven working query pattern from QUERIES_SUCCESS.md
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
            <h3>Teacher Dashboard</h3>
            <p>Please log in to view your dashboard.</p>
        </div>';
    }
    
    /**
     * Render no permission message
     */
    private function render_no_permission_message() {
        return '<div class="simple-teacher-dashboard no-permission">
            <h3>Access Denied</h3>
            <p>You do not have permission to view this dashboard. This dashboard is only available for teachers and instructors.</p>
        </div>';
    }
    
    /**
     * Render no groups message
     */
    private function render_no_groups_message($user) {
        return '<div class="simple-teacher-dashboard no-groups">
            <h3>Teacher Dashboard - ' . esc_html($user->display_name) . '</h3>
            <p>You are not assigned to any groups yet. Please contact your administrator.</p>
        </div>';
    }
    
    /**
     * Render group selector interface
     */
    private function render_group_selector($groups) {
        $html = '<div class="group-selector">';
        $html .= '<h3>Select a Group</h3>';
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
        
        // Get quiz statistics for this student
        $query = "
            SELECT 
                COUNT(DISTINCT ua.post_id) as total_quizzes,
                SUM(CASE WHEN ua.activity_status = 1 THEN 1 ELSE 0 END) as completed_quizzes,
                ROUND((SUM(CASE WHEN ua.activity_status = 1 THEN 1 ELSE 0 END) / 
                       COUNT(DISTINCT ua.post_id) * 100), 2) as success_rate,
                DATE_FORMAT(FROM_UNIXTIME(MAX(ua.activity_completed)), '%Y-%m-%d') as last_activity
            FROM {$wpdb->prefix}learndash_user_activity ua
            WHERE ua.user_id = %d
            AND ua.activity_type = 'quiz'
            AND ua.activity_completed > 0
        ";
        
        $result = $wpdb->get_row($wpdb->prepare($query, $student_id), ARRAY_A);
        
        return array(
            'total' => $result['total_quizzes'] ?? 0,
            'completed' => $result['completed_quizzes'] ?? 0,
            'success_rate' => $result['success_rate'] ?? 0,
            'last_activity' => $result['last_activity'] ?? 'Never'
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
                text-align: left;
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
        // Prepare groups data for JavaScript
        $groups_json = array();
        foreach ($groups as $group) {
            $students = $this->get_group_students($group->group_id);
            $groups_json[$group->group_id] = array(
                'name' => $group->group_name,
                'students' => $students
            );
        }
        
        $groups_data = json_encode($groups_json);
        
        return '<script>
        jQuery(document).ready(function($) {
            var groupsData = ' . $groups_data . ';
            
            // Handle group button clicks
            $(".group-btn").click(function() {
                var groupId = $(this).data("group-id");
                var groupData = groupsData[groupId];
                
                // Update button states
                $(".group-btn").removeClass("active");
                $(this).addClass("active");
                
                // Show loading
                $("#students-display").html("<div class=\"loading\">Loading students...</div>");
                
                // Simulate loading delay for better UX
                setTimeout(function() {
                    displayStudents(groupData);
                }, 300);
            });
            
            function displayStudents(groupData) {
                var html = "";
                
                if (!groupData || !groupData.students || groupData.students.length === 0) {
                    html = "<div class=\"students-table\">";
                    html += "<h4>" + groupData.name + "</h4>";
                    html += "<p>No students found in this group.</p>";
                    html += "</div>";
                } else {
                    html = "<div class=\"students-table\">";
                    html += "<h4>Students in " + groupData.name + " (" + groupData.students.length + " students)</h4>";
                    html += "<table>";
                    html += "<thead><tr><th>Student Name</th><th>Username</th><th>Email</th></tr></thead>";
                    html += "<tbody>";
                    
                    groupData.students.forEach(function(student) {
                        html += "<tr>";
                        html += "<td>" + escapeHtml(student.student_name) + "</td>";
                        html += "<td>" + escapeHtml(student.student_login) + "</td>";
                        html += "<td>" + escapeHtml(student.student_email) + "</td>";
                        html += "</tr>";
                    });
                    
                    html += "</tbody></table>";
                    html += "</div>";
                }
                
                $("#students-display").html(html);
            }
            
            function escapeHtml(text) {
                var map = {
                    "&": "&amp;",
                    "<": "&lt;",
                    ">": "&gt;",
                    "\"": "&quot;",
                    "\'": "&#039;"
                };
                return text.replace(/[&<>"\']]/g, function(m) { return map[m]; });
            }
        });
        </script>';
    }
}

// Initialize the plugin
new Simple_Teacher_Dashboard();
