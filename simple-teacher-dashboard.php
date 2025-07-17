<?php
/**
 * Plugin Name: Simple Teacher Dashboard
 * Description: A simplified teacher dashboard showing only teacher's groups, students, and grades
 * Version: 1.0.0
 * Author: Your Name
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Simple_Teacher_Dashboard {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
    }
    
    public function init() {
        // Register shortcode
        add_shortcode('teacher_dashboard', array($this, 'render_dashboard'));
    }
    
    public function render_dashboard($atts) {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            return '<div class="simple-teacher-dashboard"><p>Please log in to view your dashboard.</p></div>';
        }
        
        $current_user = wp_get_current_user();
        
        // Check if user has any teacher role
        $teacher_roles = ['group_leader', 'school_teacher', 'instructor', 'wdm_instructor'];
        $has_teacher_role = false;
        
        foreach ($teacher_roles as $role) {
            if (in_array($role, $current_user->roles)) {
                $has_teacher_role = true;
                break;
            }
        }
        
        if (!$has_teacher_role) {
            return '<div class="simple-teacher-dashboard"><p>You do not have permission to view this dashboard.</p></div>';
        }
        
        // Get teacher's groups
        $groups = $this->get_teacher_groups($current_user->ID);
        
        if (empty($groups)) {
            return '<div class="teacher-dashboard-no-groups">
                <h3>Teacher Dashboard</h3>
                <p>You are not assigned to any groups yet.</p>
            </div>';
        }
        
        // Build dashboard HTML
        $html = '<div class="simple-teacher-dashboard">';
        $html .= '<h2>My Dashboard - ' . esc_html($current_user->display_name) . '</h2>';
        
        foreach ($groups as $group) {
            $html .= $this->render_group_section($group, $current_user->ID);
        }
        
        $html .= '</div>';
        
        // Add basic CSS
        $html .= $this->get_dashboard_css();
        
        return $html;
    }
    
    private function get_teacher_groups($teacher_id) {
        global $wpdb;
        
        $group_ids = array();
        
        // Method 1: Get groups where user is a member (learndash_group_users_X pattern)
        $user_groups = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT SUBSTRING_INDEX(meta_key, '_', -1) as group_id
            FROM {$wpdb->usermeta}
            WHERE user_id = %d
            AND meta_key LIKE 'learndash_group_users_%%'
        ", $teacher_id));
        
        foreach ($user_groups as $group) {
            $group_ids[] = intval($group->group_id);
        }
        
        // Method 2: Get groups where user is a leader (learndash_group_leaders meta)
        $leader_groups = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT g.ID as group_id
            FROM {$wpdb->posts} g
            JOIN {$wpdb->postmeta} gm ON g.ID = gm.post_id
            WHERE g.post_type = 'groups'
            AND g.post_status = 'publish'
            AND gm.meta_key = 'learndash_group_leaders'
            AND gm.meta_value LIKE %s
        ", '%"' . $teacher_id . '"%'));
        
        foreach ($leader_groups as $group) {
            $group_ids[] = intval($group->group_id);
        }
        
        // Method 3: Check for single group leader meta
        $single_leader_groups = $wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT g.ID as group_id
            FROM {$wpdb->posts} g
            JOIN {$wpdb->postmeta} gm ON g.ID = gm.post_id
            WHERE g.post_type = 'groups'
            AND g.post_status = 'publish'
            AND gm.meta_key = 'learndash_group_leader'
            AND gm.meta_value = %d
        ", $teacher_id));
        
        foreach ($single_leader_groups as $group) {
            $group_ids[] = intval($group->group_id);
        }
        
        // Remove duplicates and check if we found any groups
        $group_ids = array_unique($group_ids);
        
        if (empty($group_ids)) {
            return array();
        }
        
        // Get group details for found group IDs
        $placeholders = implode(',', array_fill(0, count($group_ids), '%d'));
        
        $query = "
            SELECT DISTINCT g.ID, g.post_title as group_name
            FROM {$wpdb->posts} g
            WHERE g.post_type = 'groups'
            AND g.post_status = 'publish'
            AND g.ID IN ($placeholders)
            ORDER BY g.post_title
        ";
        
        return $wpdb->get_results($wpdb->prepare($query, ...$group_ids));
    }
    
    private function render_group_section($group, $teacher_id) {
        $html = '<div class="group-section">';
        $html .= '<h3>' . esc_html($group->group_name) . '</h3>';
        
        // Get students in this group
        $students = $this->get_group_students($group->ID);
        
        if (empty($students)) {
            $html .= '<p>No students in this group yet.</p>';
        } else {
            $html .= '<div class="students-table">';
            $html .= '<table>';
            $html .= '<thead><tr><th>Student</th><th>Quizzes Completed</th><th>Success Rate</th><th>Last Activity</th></tr></thead>';
            $html .= '<tbody>';
            
            foreach ($students as $student) {
                $quiz_stats = $this->get_student_quiz_stats($student->ID);
                $html .= '<tr>';
                $html .= '<td>' . esc_html($student->display_name) . '</td>';
                $html .= '<td>' . ($quiz_stats['completed'] ?? 0) . ' / ' . ($quiz_stats['total'] ?? 0) . '</td>';
                $html .= '<td>' . ($quiz_stats['success_rate'] ?? 0) . '%</td>';
                $html .= '<td>' . ($quiz_stats['last_activity'] ?? 'Never') . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</tbody></table>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    private function get_group_students($group_id) {
        global $wpdb;
        
        // Get students in this group using the correct approach
        $query = "
            SELECT DISTINCT u.ID, u.display_name
            FROM {$wpdb->users} u
            JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
            WHERE um.meta_key = 'learndash_group_users_{$group_id}'
            AND um.meta_value = %s
            ORDER BY u.display_name
        ";
        
        return $wpdb->get_results($wpdb->prepare($query, $group_id));
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
    
    private function get_dashboard_css() {
        return '<style>
            .simple-teacher-dashboard {
                font-family: Arial, sans-serif;
                max-width: 1200px;
                margin: 20px 0;
            }
            .simple-teacher-dashboard h2 {
                color: #333;
                border-bottom: 2px solid #0073aa;
                padding-bottom: 10px;
            }
            .group-section {
                margin: 30px 0;
                padding: 20px;
                border: 1px solid #ddd;
                border-radius: 5px;
                background: #f9f9f9;
            }
            .group-section h3 {
                color: #0073aa;
                margin-top: 0;
            }
            .students-table table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 15px;
            }
            .students-table th,
            .students-table td {
                padding: 10px;
                text-align: left;
                border-bottom: 1px solid #ddd;
            }
            .students-table th {
                background-color: #0073aa;
                color: white;
                font-weight: bold;
            }
            .students-table tr:hover {
                background-color: #f5f5f5;
            }
            .teacher-dashboard-login,
            .teacher-dashboard-no-groups {
                padding: 20px;
                border: 1px solid #ddd;
                border-radius: 5px;
                text-align: center;
                background: #f9f9f9;
            }
            .button {
                display: inline-block;
                padding: 10px 20px;
                background: #0073aa;
                color: white;
                text-decoration: none;
                border-radius: 3px;
            }
            .button:hover {
                background: #005a87;
            }
        </style>';
    }
}

// Initialize the plugin
new Simple_Teacher_Dashboard();
