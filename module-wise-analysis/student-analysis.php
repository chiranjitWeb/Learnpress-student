<?php
/**
 * Frontend Student Analysis Overview & Report via Shortcode with Live Search
 */

defined( 'ABSPATH' ) || exit;

// Register Shortcode: [mwa_student_overview]
add_shortcode( 'mwa_student_overview', 'mwa_render_frontend_student_overview' );

function mwa_render_frontend_student_overview() {
    if ( ! is_user_logged_in() ) {
        return '<p style="padding:20px; text-align:center; color:#e74c3c; font-weight:600;">' . esc_html__( 'Please log in to view this page.', 'learnpress' ) . '</p>';
    }

    global $wpdb;
    $current_user_id = get_current_user_id();
    $is_instructor_or_admin = current_user_can( 'administrator' ) || current_user_can( 'lp_teacher' ) || current_user_can( 'instructor' );

    $target_student_id = $current_user_id;

    if ( $is_instructor_or_admin && isset( $_GET['view_student_id'] ) && absint( $_GET['view_student_id'] ) > 0 ) {
        $target_student_id = absint( $_GET['view_student_id'] );
    }

    ob_start();

    echo '<div class="mwa-frontend-wrap" style="max-width:1050px; margin:0 auto; padding:15px; font-family:inherit;">';

    if ( $is_instructor_or_admin && ! isset( $_GET['view_student_id'] ) ) {
        echo '<h2 style="color:#2c3e50; margin-bottom:10px;">' . esc_html__( 'Enrolled Students Performance Overview', 'learnpress' ) . '</h2>';
        echo '<p style="color:#666; margin-bottom:15px;">' . esc_html__( 'Search or select any student below to view their detailed module performance and quiz results.', 'learnpress' ) . '</p>';

        // Live Search Input Box
        echo '<div style="margin-bottom:20px;">';
        echo '<input type="text" id="mwa-student-search" placeholder="' . esc_attr__( 'Search student by name or email...', 'learnpress' ) . '" style="width:100%; padding:10px 14px; border:1px solid #ccd0d4; border-radius:6px; font-size:14px; outline:none; box-sizing:border-box;">';
        echo '</div>';

        // Fetch enrolled students
        $students = $wpdb->get_results( 
            "SELECT DISTINCT u.ID, u.display_name, u.user_email 
             FROM {$wpdb->users} u 
             INNER JOIN {$wpdb->prefix}learnpress_user_items ui ON u.ID = ui.user_id 
             WHERE ui.item_type = 'lp_course' 
             ORDER BY u.display_name ASC" 
        );

        if ( empty( $students ) ) {
            echo '<p style="color:#e74c3c; font-weight:600;">' . esc_html__( 'No enrolled students found yet.', 'learnpress' ) . '</p>';
        } else {
            // Scrollable wrapper container for lengthy lists
            echo '<div style="max-height:500px; overflow-y:auto; border:1px solid #eee; border-radius:6px;">';
            echo '<table id="mwa-students-table" style="width:100%; border-collapse:collapse; background:#fff;">';
            echo '<thead><tr style="background:#f8f9fa; text-align:left; border-bottom:2px solid #eee; position:sticky; top:0; z-index:10;">';
            echo '<th style="padding:12px 15px;">' . esc_html__( 'Student Name', 'learnpress' ) . '</th>';
            echo '<th style="padding:12px 15px;">' . esc_html__( 'Email', 'learnpress' ) . '</th>';
            echo '<th style="padding:12px 15px; width:140px; text-align:center;">' . esc_html__( 'Action', 'learnpress' ) . '</th>';
            echo '</tr></thead><tbody>';

            foreach ( $students as $student ) {
                $report_url = add_query_arg( 'view_student_id', $student->ID );

                echo '<tr class="mwa-student-row" style="border-bottom:1px solid #eee;">';
                echo '<td class="mwa-student-name" style="padding:12px 15px;"><strong>' . esc_html( $student->display_name ) . '</strong></td>';
                echo '<td class="mwa-student-email" style="padding:12px 15px; color:#555;">' . esc_html( $student->user_email ) . '</td>';
                echo '<td style="padding:12px 15px; text-align:center;">';
                echo '<a href="' . esc_url( $report_url ) . '" style="background:#0073aa; color:#fff; padding:6px 14px; text-decoration:none; border-radius:4px; font-size:12px; display:inline-block; font-weight:600;">' . esc_html__( 'View Report', 'learnpress' ) . '</a>';
                echo '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div>';

            // Inline jQuery for instant live searching
            ?>
            <script>
            document.addEventListener("DOMContentLoaded", function() {
                var searchInput = document.getElementById("mwa-student-search");
                if (searchInput) {
                    searchInput.addEventListener("keyup", function() {
                        var filter = searchInput.value.toLowerCase();
                        var rows = document.querySelectorAll("#mwa-students-table .mwa-student-row");

                        rows.forEach(function(row) {
                            var name = row.querySelector(".mwa-student-name").textContent.toLowerCase();
                            var email = row.querySelector(".mwa-student-email").textContent.toLowerCase();

                            if (name.includes(filter) || email.includes(filter)) {
                                row.style.display = "";
                            } else {
                                row.style.display = "none";
                            }
                        });
                    });
                }
            });
            </script>
            <?php
        }

    } else {
        // Show Report for Target Student
        $student_info = get_userdata( $target_student_id );
        
        if ( $is_instructor_or_admin ) {
            $back_url = remove_query_arg( 'view_student_id' );
            echo '<p><a href="' . esc_url( $back_url ) . '" style="display:inline-block; margin-bottom:15px; text-decoration:none; color:#0073aa; font-weight:600;">&larr; ' . esc_html__( 'Back to Students List', 'learnpress' ) . '</a></p>';
        }

        if ( $student_info ) {
            if ( $is_instructor_or_admin ) {
                echo '<h2 style="color:#2c3e50; margin-bottom:20px;">' . sprintf( esc_html__( 'Analysis Report for: %s', 'learnpress' ), esc_html( $student_info->display_name ) ) . '</h2>';
            } else {
                echo '<h2 style="color:#2c3e50; margin-bottom:20px;">' . esc_html__( 'My Analysis Report', 'learnpress' ) . '</h2>';
            }

            $course_ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT item_id FROM {$wpdb->prefix}learnpress_user_items WHERE user_id = %d AND item_type = 'lp_course' ORDER BY user_item_id ASC",
                    $target_student_id
                )
            );

            if ( empty( $course_ids ) ) {
                echo '<p style="padding:20px; text-align:center; color:#666; background:#fff; border-radius:6px; border:1px solid #eee;">' . esc_html__( 'No enrolled courses found for this user.', 'learnpress' ) . '</p>';
            } else {
                echo '<div class="mwa-wrap" style="clear: both; overflow: hidden;">';

                // foreach ( $course_ids as $course_id ) {
                //     $course_id = absint( $course_id );
                //     if ( ! $course_id ) continue;

                //     $course_title = get_the_title( $course_id );
                //     if ( ! $course_title ) continue;

                //     $modules = mwa_get_course_modules_data( $course_id, $target_student_id );
                //     if ( empty( $modules ) ) continue;

                //     echo '<details class="mwa-course-block" style="margin-bottom:15px; border:1px solid #e1e1e1; background:#fff; border-radius:8px; padding:15px; box-shadow:0 1px 3px rgba(0,0,0,0.05);">';
                //     echo '<summary class="mwa-course-title" style="font-weight:bold; cursor:pointer; font-size:16px; color:#2c3e50;">' . esc_html( $course_title ) . '</summary>';
                //     echo '<div class="mwa-course-body" style="margin-top:15px;">';
                    
                //     mwa_render_overall_summary( $modules );
                //     mwa_render_chart( $course_id, $modules );
                //     mwa_render_module_list( $modules );

                //     echo '</div></details>';
                // }
                echo '<h1 style="color:#000">This for only Teacher</h1>';
                echo '</div>';
            }
        } else {
            echo '<p style="color:#e74c3c;">' . esc_html__( 'Invalid student.', 'learnpress' ) . '</p>';
        }
    }

    echo '</div>';
    return ob_get_clean();
}