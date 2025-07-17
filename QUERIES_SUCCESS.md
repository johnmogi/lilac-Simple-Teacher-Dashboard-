# LearnDash Quiz Success Rate Queries

## 1. Individual Quiz Attempts Query
```sql
SELECT 
    s.ID as student_id,
    s.display_name as student_name,
    q.post_title as quiz_name,
    c.post_title as course_name,
    MAX(ua.activity_status) as latest_status,
    MAX(ua.activity_completed) as latest_completion,
    COUNT(ua.activity_id) as attempts,
    SUM(CASE WHEN ua.activity_status = 1 THEN 1 ELSE 0 END) as successful_attempts,
    ROUND((SUM(CASE WHEN ua.activity_status = 1 THEN 1 ELSE 0 END) / COUNT(ua.activity_id) * 100), 2) as success_rate,
    DATE_FORMAT(FROM_UNIXTIME(MAX(ua.activity_completed)), '%Y-%m-%d %H:%i:%s') as last_attempt_date
FROM edc_learndash_user_activity ua
JOIN edc_users s ON ua.user_id = s.ID
JOIN edc_posts q ON ua.post_id = q.ID
JOIN edc_posts c ON ua.course_id = c.ID
WHERE ua.activity_type = 'quiz'
GROUP BY s.ID, q.ID, c.ID
ORDER BY s.display_name, c.post_title, q.post_title;
```

### Fields:
- student_id: Unique student ID
- student_name: Student's display name
- quiz_name: Name of the quiz (note: may show as question marks due to encoding)
- course_name: Name of the course
- latest_status: 0 for incomplete, 1 for completed
- latest_completion: Unix timestamp of last attempt
- attempts: Number of attempts for this quiz
- successful_attempts: Number of successful attempts
- success_rate: Percentage of successful attempts
- last_attempt_date: Formatted date/time of last attempt

## 2. Student Summary Query
```sql
SELECT 
    s.ID as student_id,
    s.display_name as student_name,
    COUNT(DISTINCT ua.post_id) as total_quizzes,
    SUM(CASE WHEN ua.activity_status = 1 THEN 1 ELSE 0 END) as completed_quizzes,
    ROUND((SUM(CASE WHEN ua.activity_status = 1 THEN 1 ELSE 0 END) / 
           COUNT(DISTINCT ua.post_id) * 100), 2) as overall_success_rate,
    GROUP_CONCAT(DISTINCT c.post_title ORDER BY c.post_title SEPARATOR ', ') as courses,
    MIN(FROM_UNIXTIME(ua.activity_completed)) as first_quiz_date,
    MAX(FROM_UNIXTIME(ua.activity_completed)) as last_quiz_date
FROM edc_learndash_user_activity ua
JOIN edc_users s ON ua.user_id = s.ID
JOIN edc_posts c ON ua.course_id = c.ID
WHERE ua.activity_type = 'quiz'
GROUP BY s.ID
ORDER BY overall_success_rate DESC;
```

### Fields:
- student_id: Unique student ID
- student_name: Student's display name
- total_quizzes: Total number of unique quizzes attempted
- completed_quizzes: Number of quizzes completed successfully
- overall_success_rate: Percentage of quizzes completed successfully
- courses: List of courses the student has quizzes in
- first_quiz_date: Date of first quiz attempt
- last_quiz_date: Date of last quiz attempt

## 3. Group Success Rate Query
```sql
SELECT 
    g.post_title as group_name,
    t.display_name as teacher_name,
    COUNT(DISTINCT s.ID) as student_count,
    COUNT(DISTINCT CASE 
        WHEN ua.activity_type = 'quiz' AND ua.activity_status = 1 THEN ua.post_id 
        ELSE NULL 
    END) as total_successful_attempts,
    COUNT(DISTINCT CASE 
        WHEN ua.activity_type = 'quiz' THEN ua.post_id 
        ELSE NULL 
    END) as total_attempts,
    ROUND((COUNT(DISTINCT CASE 
        WHEN ua.activity_type = 'quiz' AND ua.activity_status = 1 THEN ua.post_id 
        ELSE NULL 
    END) / COUNT(DISTINCT CASE 
        WHEN ua.activity_type = 'quiz' THEN ua.post_id 
        ELSE NULL 
    END) * 100), 2) as group_success_rate,
    GROUP_CONCAT(DISTINCT CONCAT(
        s.display_name, ' (', 
        ROUND((COUNT(CASE WHEN ua.activity_type = 'quiz' AND ua.activity_status = 1 THEN ua.post_id END) / 
               COUNT(CASE WHEN ua.activity_type = 'quiz' THEN ua.post_id END) * 100), 2), 
        '%)')
        ORDER BY s.display_name SEPARATOR ', ') as student_success_rates
FROM edc_learndash_user_activity ua
JOIN edc_users s ON ua.user_id = s.ID
JOIN edc_usermeta sm ON s.ID = sm.user_id
JOIN edc_usermeta tm ON tm.meta_key LIKE 'learndash_group_leaders_%'
JOIN edc_users t ON tm.user_id = t.ID
JOIN edc_posts g ON g.ID = SUBSTRING_INDEX(tm.meta_key, '_', -1)
JOIN edc_posts c ON ua.course_id = c.ID
WHERE ua.activity_type = 'quiz'
AND sm.meta_key LIKE 'learndash_group_users_%'
AND g.ID = SUBSTRING_INDEX(sm.meta_key, '_', -1)
AND g.post_type = 'groups'
AND g.post_status = 'publish'
GROUP BY g.ID, g.post_title, t.display_name
ORDER BY group_success_rate DESC;
```

### Fields:
- group_name: Name of the group
- teacher_name: Name of the group teacher
- student_count: Number of students in the group
- total_successful_attempts: Number of successful quiz attempts across all students
- total_attempts: Total number of quiz attempts across all students
- group_success_rate: Percentage success rate for the entire group
- student_success_rates: List of individual student success rates

## Notes on Implementation
1. All queries use proper GROUP BY clauses to comply with MySQL's ONLY_FULL_GROUP_BY mode
2. Success rates are calculated as (successful_attempts / total_attempts) * 100
3. Date formatting uses FROM_UNIXTIME to convert timestamps
4. GROUP_CONCAT is used to aggregate multiple values into single strings
5. All queries include proper JOIN conditions to maintain data integrity

## Common Issues and Solutions
1. **Encoding Issues**: 
   - Quiz and course names may appear as question marks due to character encoding
   - This is a display issue and doesn't affect the query results

2. **Time Zone**: 
   - All timestamps are stored in Unix time and converted to local time zone
   - Adjust the date format if needed using MySQL's CONVERT_TZ function

3. **Performance Considerations**: 
   - Large datasets may require indexing on:
     - edc_learndash_user_activity(activity_type, activity_status, user_id)
     - edc_users(ID)
     - edc_posts(ID, post_type)
     - edc_usermeta(user_id, meta_key)

## Additional Metrics to Consider
1. Time-based analysis:
   - Average time taken per quiz
   - Time between quiz attempts
   - Success rate over time

2. Course-specific metrics:
   - Success rate per course
   - Difficulty level analysis
   - Common failure points

3. Teacher-specific metrics:
   - Teacher success rate across all groups
   - Teacher improvement over time
   - Teacher-student success rate correlation


//  student GROUPS 

SELECT 
    -- Teacher Information
    t.ID as teacher_id,
    t.display_name as teacher_name,
    t.user_login as teacher_login,
    t.user_email as teacher_email,
    
    -- Group Information
    g.ID as group_id,
    g.post_title as group_name,
    g.post_status as group_status,
    
    -- Student Information
    s.ID as student_id,
    s.display_name as student_name,
    s.user_login as student_login,
    s.user_email as student_email
FROM edc_users t
-- Get all group leaders
JOIN edc_usermeta glm ON t.ID = glm.user_id 
    AND glm.meta_key LIKE '%group_leader%'
JOIN edc_posts g ON g.ID = CAST(SUBSTRING_INDEX(glm.meta_key, '_', -1) AS UNSIGNED)
-- Get students in those groups
JOIN edc_usermeta sm ON sm.meta_key = CONCAT('learndash_group_users_', g.ID)
JOIN edc_users s ON s.ID = sm.user_id
WHERE g.post_status = 'publish'
ORDER BY t.display_name, g.post_title, s.display_name;