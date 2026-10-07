<?php
/**
 * Plugin Name: Module Wise Analysis for LearnPress
 * Description: Adds a Module Wise Analysis tab and Student Analysis tab to LearnPress student profiles with accurate performance, speed indicators, print PDF support, and instructor overview.
 * Version: 2.1.0
 * Author: Chiranjit chatterjee
 */
defined( 'ABSPATH' ) || exit;

define( 'MWA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MWA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MWA_PLUGIN_VERSION', '2.8.0' );

// Load Assets
add_action( 'wp_enqueue_scripts', 'mwa_enqueue_assets' );
function mwa_enqueue_assets() {
    if ( is_admin() ) return;

    wp_enqueue_style( 'dashicons' );
    wp_enqueue_style( 'mwa-style', MWA_PLUGIN_URL . 'assets/css/style.css', array(), MWA_PLUGIN_VERSION );
    wp_enqueue_script( 'mwa-chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js', array(), '4.4.1', true );
    wp_enqueue_script( 'mwa-script', MWA_PLUGIN_URL . 'assets/js/script.js', array( 'mwa-chartjs' ), MWA_PLUGIN_VERSION, true );
}

// Include Separate Student Analysis File
require_once MWA_PLUGIN_DIR . 'student-analysis.php';

/*
|--------------------------------------------------------------------------
| LearnPress Profile Tabs (Module Analysis for Students)
|--------------------------------------------------------------------------
*/
add_filter( 'learn-press/profile-tabs', 'mwa_add_profile_tabs', 9999 );
function mwa_add_profile_tabs( $tabs ) {
    $tabs['module_analysis'] = array(
        'title'    => __( 'Module Wise Analysis', 'learnpress' ),
        'slug'     => 'module-analysis',
        'icon'     => '<i class="fa-solid fa-chart-line"></i>',
        'callback' => 'mwa_render_profile_tab',
        'priority' => 9,
    );
    return $tabs;
}

function mwa_render_profile_tab() {
    try {
        mwa_render_profile_tab_content();
    } catch ( Throwable $e ) {
        echo '<div style="padding:15px; background:#f8d7da; color:#721c24; border-radius:4px;">Error: ' . esc_html( $e->getMessage() ) . '</div>';
    }
}

function mwa_render_profile_tab_content() {
    global $wpdb;
    $profile = class_exists('LP_Profile') ? LP_Profile::instance() : null;
    $user_id = ($profile && method_exists($profile, 'get_user') && $profile->get_user()) ? $profile->get_user()->get_id() : get_current_user_id();

    if ( ! $user_id ) {
        echo '<p>' . esc_html__( 'Please log in to view your analysis report.', 'learnpress' ) . '</p>';
        return;
    }

    $course_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT DISTINCT item_id FROM {$wpdb->prefix}learnpress_user_items WHERE user_id = %d AND item_type = 'lp_course' ORDER BY user_item_id ASC",
            $user_id
        )
    );

    if ( empty( $course_ids ) ) {
        echo '<p style="padding:20px; text-align:center; color:#666;">' . esc_html__( 'No enrolled courses found for this user.', 'learnpress' ) . '</p>';
        return;
    }

    echo '<div class="mwa-wrap" style="clear: both; overflow: hidden;">';
    echo '<h1 style="text-align:center;color:#000;margin-bottom:20px;">' . esc_html__( 'Analysis Report', 'learnpress' ) . '</h1>';

    foreach ( $course_ids as $course_id ) {
        $course_id = absint( $course_id );
        if ( ! $course_id ) continue;

        $course_title = get_the_title( $course_id );
        if ( ! $course_title ) continue;

        $modules = mwa_get_course_modules_data( $course_id, $user_id );
        if ( empty( $modules ) ) continue;

        echo '<details class="mwa-course-block">';
        echo '<summary class="mwa-course-title">' . esc_html( $course_title ) . '</summary>';
        echo '<div class="mwa-course-body">';
        mwa_render_overall_summary( $modules );
        mwa_render_chart( $course_id, $modules );
        mwa_render_module_list( $modules );
        echo '</div></details>';
    }
    echo '</div>';
}


/*
|--------------------------------------------------------------------------
| 5. Get Course Sections / Modules
|--------------------------------------------------------------------------
*/

function mwa_get_course_modules_data( $course_id, $user_id ) {
    global $wpdb;

    $sections = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT section_id, section_name FROM {$wpdb->prefix}learnpress_sections WHERE section_course_id = %d ORDER BY section_order ASC",
            $course_id
        ),
        ARRAY_A
    );

    if ( empty( $sections ) ) {
        return array();
    }

    $modules = array();

    foreach ( $sections as $section ) {
        $section_id = absint( $section['section_id'] );

        $quiz_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT si.item_id FROM {$wpdb->prefix}learnpress_section_items si INNER JOIN {$wpdb->posts} p ON p.ID = si.item_id WHERE si.section_id = %d AND p.post_type = 'lp_quiz' ORDER BY si.item_id ASC",
                $section_id
            )
        );

        $quiz_ids = array_map( 'absint', $quiz_ids );

        $attempted = 0;
        $total_questions = 0;
        $correct = 0;
        $wrong = 0;
        $answered = 0;
        $time_spent = 0;
        $quizzes = array();

        foreach ( $quiz_ids as $quiz_id ) {
            if ( ! $quiz_id ) continue;

            $result = mwa_get_quiz_result_for_user( $quiz_id, $user_id );

            if ( $result === null ) {
                $quizzes[] = array(
                    'quiz_id'           => $quiz_id,
                    'title'             => get_the_title( $quiz_id ),
                    'attempted'         => false,
                    'question_count'    => 0,
                    'question_correct'  => 0,
                    'question_wrong'    => 0,
                    'question_answered' => 0,
                    'percent'           => null,
                    'time_spent_sec'    => 0,
                    'questions_detail'  => array(),
                    'speed_label'       => '—',
                );
                continue;
            }

            $attempted++;
            $total_questions += $result['question_count'];
            $correct += $result['question_correct'];
            $wrong += $result['question_wrong'];
            $answered += $result['question_answered'];
            $time_spent += $result['time_spent_sec'];

            $avg_time_per_q = ($result['question_count'] > 0) ? ($result['time_spent_sec'] / $result['question_count']) : 0;
            $speed_label = 'Balanced';
            if ($avg_time_per_q > 0 && $avg_time_per_q < 30) {
                $speed_label = 'Fast Solver';
            } elseif ($avg_time_per_q > 90) {
                $speed_label = 'Needs Practice';
            }

            $quizzes[] = array(
                'quiz_id'           => $quiz_id,
                'title'             => get_the_title( $quiz_id ),
                'attempted'         => true,
                'question_count'    => $result['question_count'],
                'question_correct'  => $result['question_correct'],
                'question_wrong'    => $result['question_wrong'],
                'question_answered' => $result['question_answered'],
                'percent'           => $result['percent'],
                'time_spent_sec'    => $result['time_spent_sec'],
                'questions_detail'  => $result['questions_detail'],
                'speed_label'       => $speed_label,
            );
        }

        $avg_percent = null;
        if ( $total_questions > 0 ) {
            $avg_percent = max( 0, min( 100, ( $correct / $total_questions ) * 100 ) );
        }

        if ( $attempted === 0 ) {
            $label = __( 'Not attempted', 'learnpress' );
        } elseif ( $avg_percent === null ) {
            $label = __( 'Attempted', 'learnpress' );
        } elseif ( $avg_percent >= 80 ) {
            $label = __( 'Strong', 'learnpress' );
        } elseif ( $avg_percent >= 50 ) {
            $label = __( 'Average', 'learnpress' );
        } else {
            $label = __( 'Weak', 'learnpress' );
        }

        $modules[] = array(
            'section_id'      => $section_id,
            'name'            => $section['section_name'],
            'total_quizzes'   => count( $quiz_ids ),
            'attempted'       => $attempted,
            'avg_percent'     => $avg_percent,
            'time_spent_sec'  => $time_spent,
            'label'           => $label,
            'correct'         => $correct,
            'wrong'           => $wrong,
            'answered'        => $answered,
            'total_questions' => $total_questions,
            'quizzes'         => $quizzes,
        );
    }

    return $modules;
}


/*
|--------------------------------------------------------------------------
| 6. Get Latest Quiz Result & Detailed Question List
|-------------------------------------------------------------------------- 
| Uses the exact structure confirmed from your LearnPress database: | 
| questions | mark | user_mark | question_count | question_empty | 
| question_answered | question_wrong | question_correct | result | time_spend 
*/

function mwa_get_quiz_result_for_user( $quiz_id, $user_id ) {
    global $wpdb;

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT user_item_id, start_time, end_time, status, graduation FROM {$wpdb->prefix}learnpress_user_items WHERE user_id = %d AND item_id = %d AND item_type = 'lp_quiz' AND status IN ('completed', 'passed', 'failed') ORDER BY user_item_id DESC LIMIT 1",
            $user_id,
            $quiz_id
        ),
        ARRAY_A
    );

    if ( ! $row ) return null;

    $result_raw = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT result FROM {$wpdb->prefix}learnpress_user_item_results WHERE user_item_id = %d ORDER BY id DESC LIMIT 1",
            $row['user_item_id']
        )
    );

    if ( empty( $result_raw ) ) return null;

    $result_data = json_decode( $result_raw, true );
    if ( ! is_array( $result_data ) ) return null;

    $questions = isset( $result_data['questions'] ) && is_array( $result_data['questions'] ) ? $result_data['questions'] : array();

    $question_count = 0;
    $question_correct = 0;
    $question_wrong = 0;
    $question_answered = 0;
    $questions_detail = array();

    if ( ! empty( $questions ) ) {
        foreach ( $questions as $q_id => $question ) {
            if ( ! is_array( $question ) ) continue;

            $question_count++;
            $is_answered = false;
            if ( isset( $question['answered'] ) && $question['answered'] !== '' && $question['answered'] !== null ) {
                $question_answered++;
                $is_answered = true;
            }

            $is_correct = false;
            if ( isset( $question['correct'] ) && ( $question['correct'] === true || $question['correct'] === 1 || $question['correct'] === '1' || $question['correct'] === 'true' ) ) {
                $question_correct++;
                $is_correct = true;
            }

            $questions_detail[] = array(
                'question_id' => $q_id,
                'title'       => get_the_title( $q_id ),
                'answered'    => $is_answered,
                'correct'     => $is_correct,
            );
        }

        $question_wrong = max( 0, $question_answered - $question_correct );
    }
    /* * ------------------------------------------------------- * FALLBACK * ------------------------------------------------------- * 
    * If the questions array is unavailable, use LearnPress's * own stored totals. */

    if ( $question_count <= 0 ) {
        $question_count = isset( $result_data['question_count'] ) ? absint( $result_data['question_count'] ) : 0;
    }
    if ( $question_answered <= 0 ) {
        $question_answered = isset( $result_data['question_answered'] ) ? absint( $result_data['question_answered'] ) : 0;
    }
    if ( $question_correct <= 0 ) {
        $question_correct = isset( $result_data['question_correct'] ) ? absint( $result_data['question_correct'] ) : 0;
    }
    if ( $question_wrong <= 0 ) {
        $question_wrong = max( 0, $question_answered - $question_correct );
    }
    /* * ------------------------------------------------------- * SCORE * ------------------------------------------------------- * 
    * Calculate from actual questions. * * Example: * * 3 correct / 3 questions = 100% * * 5 correct / 10 questions = 50% */
    $percent = null;
    if ( $question_count > 0 ) {
        $percent = max( 0, min( 100, ( $question_correct / $question_count ) * 100 ) );
    } elseif ( isset( $result_data['result'] ) ) {
        $percent = max( 0, min( 100, floatval( $result_data['result'] ) ) );
    }
   /* * ------------------------------------------------------- * TIME * ------------------------------------------------------- */
    $time_spent_sec = 0;
    if ( ! empty( $result_data['time_spend'] ) ) {
        $parts = array_map( 'intval', explode( ':', trim( $result_data['time_spend'] ) ) );
        if ( count( $parts ) === 3 ) {
            $time_spent_sec = ( $parts[0] * 3600 ) + ( $parts[1] * 60 ) + $parts[2];
        }
    }
/* * Fallback: * * start_time → end_time */
    if ( $time_spent_sec <= 0 && ! empty( $row['start_time'] ) && ! empty( $row['end_time'] ) ) {
        $start_ts = strtotime( $row['start_time'] );
        $end_ts = strtotime( $row['end_time'] );
        if ( $start_ts !== false && $end_ts !== false && $end_ts > $start_ts ) {
            $diff = $end_ts - $start_ts;
            if ( $diff <= 6 * HOUR_IN_SECONDS ) {
                $time_spent_sec = $diff;
            }
        }
    }
/* * Return clean result. */
    return array(
        'percent'          => $percent,
        'time_spent_sec'   => absint( $time_spent_sec ),
        'question_correct' => absint( $question_correct ),
        'question_count'   => absint( $question_count ),
        'question_wrong'   => absint( $question_wrong ),
        'question_answered'=> absint( $question_answered ),
        'user_item_id'     => absint( $row['user_item_id'] ),
        'questions_detail' => $questions_detail,
    );
}


/*
|--------------------------------------------------------------------------
| 7. Format Time
|--------------------------------------------------------------------------
*/

function mwa_format_time( $seconds ) {
    $seconds = absint( $seconds );
    if ( $seconds <= 0 ) return '—';

    $hours = floor( $seconds / 3600 );
    $minutes = floor( ( $seconds % 3600 ) / 60 );
    $remaining_seconds = $seconds % 60;

    $parts = array();
    if ( $hours > 0 ) $parts[] = $hours . ' hr';
    if ( $hours > 0 || $minutes > 0 ) $parts[] = $minutes . ' min';
    $parts[] = sprintf( '%02d sec', $remaining_seconds );

    return implode( ' ', $parts );
}


/*
|--------------------------------------------------------------------------
| 8. Label Class
|--------------------------------------------------------------------------
*/

function mwa_label_class( $label ) {
    $map = array(
        'Strong'        => 'mwa-strong',
        'Average'       => 'mwa-average',
        'Weak'          => 'mwa-weak',
        'Attempted'     => 'mwa-attempted',
        'Not attempted' => 'mwa-none',
    );
    return isset( $map[$label] ) ? $map[$label] : 'mwa-none';
}


/*
|--------------------------------------------------------------------------
| 9. Overall Course Summary with "+more need" Feature
|--------------------------------------------------------------------------
*/

function mwa_render_overall_summary( $modules ) {
    $total_modules = count( $modules );
    $attempted_modules = 0;
    $total_quizzes = 0;
    $attempted_quizzes = 0;
    $total_questions = 0;
    $total_correct = 0;
    $total_wrong = 0;
    $total_time = 0;
    $weak_modules = array();

    foreach ( $modules as $module ) {
        /* * Module attempted. */
        if ( $module['attempted'] > 0 ) $attempted_modules++;
        /* * Quiz totals. */
        $total_quizzes += $module['total_quizzes'];
        $attempted_quizzes += $module['attempted'];
        /* * Question totals. */
        $total_questions += $module['total_questions'];
        $total_correct += $module['correct'];
        $total_wrong += $module['wrong'];
        /* * Time. */
        $total_time += $module['time_spent_sec'];
        /* * Weak modules. */
        if ( $module['label'] === 'Weak' ) {
            $weak_modules[] = $module['name'];
        }
    }
    /* * Actual overall score. */
    $overall_score = null;
    if ( $total_questions > 0 ) {
        $overall_score = max( 0, min( 100, ( $total_correct / $total_questions ) * 100 ) );
    }
    /* * Modules. */
    echo '<div class="mwa-summary">';
    
    echo '<div class="mwa-summary-item"><span class="mwa-summary-label">' . esc_html__( 'Modules Attempted', 'learnpress' ) . '</span><span class="mwa-summary-value">' . esc_html( $attempted_modules . ' / ' . $total_modules ) . '</span></div>';
    /* * Quizzes. */
    echo '<div class="mwa-summary-item"><span class="mwa-summary-label">' . esc_html__( 'Quizzes Attempted', 'learnpress' ) . '</span><span class="mwa-summary-value">' . esc_html( $attempted_quizzes . ' / ' . $total_quizzes ) . '</span></div>';
    /* * Overall Score. */
    echo '<div class="mwa-summary-item"><span class="mwa-summary-label">' . esc_html__( 'Overall Score', 'learnpress' ) . '</span><span class="mwa-summary-value">' . ( $overall_score !== null ? esc_html( round( $overall_score, 1 ) . '%' ) : '—' ) . '</span></div>';
    /* * Questions. */
    echo '<div class="mwa-summary-item"><span class="mwa-summary-label">' . esc_html__( 'Correct Answers', 'learnpress' ) . '</span><span class="mwa-summary-value">' . esc_html( $total_correct . ' / ' . $total_questions ) . '</span></div>';
    /* * Wrong. */
    echo '<div class="mwa-summary-item"><span class="mwa-summary-label">' . esc_html__( 'Wrong Answers', 'learnpress' ) . '</span><span class="mwa-summary-value">' . esc_html( $total_wrong ) . '</span></div>';
    /* * Time. */
    echo '<div class="mwa-summary-item"><span class="mwa-summary-label">' . esc_html__( 'Total Time Spent', 'learnpress' ) . '</span><span class="mwa-summary-value">' . esc_html( mwa_format_time( $total_time ) ) . '</span></div>';
    /* * Weak modules. */
    if ( ! empty( $weak_modules ) ) {
        $count = count( $weak_modules );
        echo '<div class="mwa-summary-item mwa-summary-weak"><span class="mwa-summary-label">' . esc_html( sprintf( _n( '%d module needs practice', '%d modules need practice', $count, 'learnpress' ), $count ) ) . '</span>';
        if ( $count > 3 ) {
            $visible_names = array_slice( $weak_modules, 0, 3 );
            $hidden_names = array_slice( $weak_modules, 3 );
            $more_count = count( $hidden_names );
            echo '<span class="mwa-summary-value mwa-summary-weak-list">' . esc_html( implode( ', ', $visible_names ) );
            echo '<details class="mwa-weak-details"><summary class="mwa-more-toggle">+' . intval( $more_count ) . ' more</summary>';
            echo '<span class="mwa-hidden-weak-names">' . esc_html( ', ' . implode( ', ', $hidden_names ) ) . '</span></details></span>';
        } else {
            echo '<span class="mwa-summary-value mwa-summary-weak-list">' . esc_html( implode( ', ', $weak_modules ) ) . '</span>';
        }
        echo '</div>';
    }

    echo '</div>';
}


/*
|--------------------------------------------------------------------------
| 10. Chart
|--------------------------------------------------------------------------
*/

function mwa_render_chart( $course_id, $modules ) {
    $labels = array();
    $values = array();
    $colors = array();

    foreach ( $modules as $module ) {
        $labels[] = $module['name'];
        $values[] = $module['avg_percent'] !== null ? round( $module['avg_percent'], 1 ) : 0;

        switch ( $module['label'] ) {
            case 'Strong': $colors[] = '#27ae60'; break;
            case 'Average': $colors[] = '#e67e22'; break;
            case 'Weak': $colors[] = '#e74c3c'; break;
            default: $colors[] = '#d7dade'; break;
        }
    }

    echo '<div class="mwa-chart-wrap">';
    echo '<canvas class="mwa-chart-canvas" data-labels="' . esc_attr( wp_json_encode( $labels ) ) . '" data-values="' . esc_attr( wp_json_encode( $values ) ) . '" data-colors="' . esc_attr( wp_json_encode( $colors ) ) . '" id="mwa-chart-' . esc_attr( $course_id ) . '"></canvas>';
    echo '</div>';
}


/*
|--------------------------------------------------------------------------
| 11. Module List with Question Breakdown & Speed Indicators
|--------------------------------------------------------------------------
*/

function mwa_render_module_list( $modules ) {
    echo '<ul class="mwa-module-list">';

    foreach ( $modules as $module ) {
        $class = mwa_label_class( $module['label'] );
        $bar_pct = $module['avg_percent'] !== null ? round( $module['avg_percent'] ) : 0;

        echo '<li class="mwa-module-row ' . esc_attr( $class ) . '">';
        /* * Module header. */
        echo '<div class="mwa-module-top">';
        echo '<span class="mwa-module-name">' . esc_html( $module['name'] ) . '</span>';
        echo '<span class="mwa-module-badge">' . esc_html( $module['label'] ) . '</span>';
        echo '</div>';
        /* * Progress bar. */
        echo '<div class="mwa-bar-track"><div class="mwa-bar-fill" style="width:' . esc_attr( $bar_pct ) . '%;"></div></div>';
        /* * Module meta. */
        echo '<div class="mwa-module-meta">';
        /* * Quizzes. */
        echo '<span><strong>' . esc_html__( 'Quizzes:', 'learnpress' ) . '</strong> ' . esc_html( $module['attempted'] . ' / ' . $module['total_quizzes'] ) . '</span>';
        /* * Correct. */
        echo '<span><strong>' . esc_html__( 'Correct:', 'learnpress' ) . '</strong> ' . esc_html( $module['correct'] . ' / ' . $module['total_questions'] ) . '</span>';
        /* * Wrong. */
        echo '<span><strong>' . esc_html__( 'Wrong:', 'learnpress' ) . '</strong> ' . esc_html( $module['wrong'] ) . '</span>';
        /* Answered. */ 
        //echo '<span>'; echo '<strong>'; echo esc_html__( 'Answered:', 'learnpress' ); echo '</strong> '; echo esc_html( $module['answered'] ); echo '</span>';
        echo '<span><strong>' . esc_html__( 'Score:', 'learnpress' ) . '</strong> ' . ( $module['avg_percent'] !== null ? esc_html( round( $module['avg_percent'], 1 ) . '%' ) : '—' ) . '</span>';
        echo '<span><strong>' . esc_html__( 'Time:', 'learnpress' ) . '</strong> ' . esc_html( mwa_format_time( $module['time_spent_sec'] ) ) . '</span>';
        echo '</div>';

        if ( $module['label'] === 'Weak' ) {
            echo '<div class="mwa-weak-tip"><span class="dashicons dashicons-info"></span>' . esc_html__( 'You need more practice in this module. Try reviewing the lesson notes before taking the quiz again.', 'learnpress' ) . '</div>';
        }

        if ( ! empty( $module['quizzes'] ) ) {
            echo '<details style="margin-top:15px; border-top:1px dashed #ddd; padding-top:10px;">';
            echo '<summary style="cursor:pointer;font-weight:600;color:#2c3e50;">' . esc_html__( 'Quiz & Question Details', 'learnpress' ) . '</summary>';
            echo '<div style="margin-top:10px;">';

            foreach ( $module['quizzes'] as $quiz ) {
                echo '<div style="padding:10px; background:#fcfcfc; border:1px solid #eee; border-radius:6px; margin-bottom:10px;">';
                echo '<div style="display:flex; justify-content:space-between; align-items:center;">';
                echo '<strong style="color:#333;">' . esc_html( $quiz['title'] ) . '</strong>';
                if ( $quiz['attempted'] ) {
                    echo '<span style="font-size:11px; padding:2px 6px; background:#f1f2f6; border-radius:10px; font-weight:600;">' . esc_html( $quiz['speed_label'] ) . '</span>';
                }
                echo '</div>';
                
                echo '<div style="font-size:13px;color:#666;margin-top:4px;">';

                if ( ! $quiz['attempted'] ) {
                    echo '<span style="color:#999;">' . esc_html__( 'Not attempted', 'learnpress' ) . '</span>';
                } else {
                    echo esc_html__( 'Questions:', 'learnpress' ) . ' ' . esc_html( $quiz['question_count'] ) . ' | ';
                    echo esc_html__( 'Correct:', 'learnpress' ) . ' ' . esc_html( $quiz['question_correct'] ) . ' | ';
                    echo esc_html__( 'Wrong:', 'learnpress' ) . ' ' . esc_html( $quiz['question_wrong'] ) . ' | ';
                    echo esc_html__( 'Score:', 'learnpress' ) . ' ' . ( $quiz['percent'] !== null ? esc_html( round( $quiz['percent'], 1 ) . '%' ) : '—' ) . ' | ';
                    echo esc_html__( 'Time:', 'learnpress' ) . ' ' . esc_html( mwa_format_time( $quiz['time_spent_sec'] ) );

                    if ( ! empty( $quiz['questions_detail'] ) ) {
                        echo '<ul style="margin:8px 0 0 15px; padding:0; list-style:none;">';
                        foreach ( $quiz['questions_detail'] as $q_item ) {
                            $icon = $q_item['correct'] ? '✅' : '❌';
                            $q_title = ! empty( $q_item['title'] ) ? $q_item['title'] : 'Question #' . $q_item['question_id'];
                            echo '<li style="font-size:12px; margin-bottom:4px;">' . $icon . ' ' . esc_html( $q_title ) . '</li>';
                        }
                        echo '</ul>';
                    }
                }

                echo '</div></div>';
            }

            echo '</div></details>';
        }

        echo '</li>';
    }

    echo '</ul>';
}

// ───────────────────────────────────────────── 
// 12. Temporary debug tool — remove once the percent meta_key is confirmed 
// add this any of pages ?lp_debug_quiz=1
// ─────────────────────────────────────────────
add_action( 'wp_footer', function () {
    if ( empty( $_GET['lp_debug_quiz'] ) || ! is_user_logged_in() ) {
        return;
    }

    global $wpdb;
    $user_id = get_current_user_id();

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}learnpress_user_items
         WHERE user_id = %d AND item_type = 'lp_quiz'
         ORDER BY user_item_id DESC LIMIT 1",
        $user_id
    ), ARRAY_A );

    echo '<pre style="background:#000;color:#0f0;padding:20px;white-space:pre-wrap;">';

    if ( ! $row ) {
        echo "No completed quiz found for this user.";
        echo '</pre>';
        return;
    }

    echo "== wp_learnpress_user_items row ==\n";
    print_r( $row );

    $result_row = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}learnpress_user_item_results WHERE user_item_id = %d",
        $row['user_item_id']
    ), ARRAY_A );

    echo "\n== matching wp_learnpress_user_item_results row ==\n";
    print_r( $result_row );

    if ( ! empty( $result_row['result'] ) ) {
        echo "\n== decoded 'result' JSON ==\n";
        print_r( json_decode( $result_row['result'], true ) );
    }
    echo '</pre>';
} );