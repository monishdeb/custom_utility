<?php

use Drupal\views\ViewExecutable;

/**
 * Implements hook_views_pre_render().
 */
function ra_custom_utility_views_pre_render(Drupal\views\ViewExecutable $view) {
  if ($view->id() == 'user_search' || $view->id() == 'membersearch') {
    $unique_uuids = $new_view_result = [];
    $new_row_index = 0;
    // Loop through results and filter out duplicate results.
    foreach ($view->result as $row) { 
      // Use contact_id so this works for all entities
        $uuid = $row->id;
        //\Drupal::logger('ra_custom_utility')->error(print_r($uuid, true));
        if (!in_array($uuid, $unique_uuids)) {
          $row->index = $new_row_index;
          $new_view_result[] = $row;
          $unique_uuids[] = $uuid;
          $new_row_index++;
        }
    }
    $view->result = $new_view_result;
	$view->total_rows = $new_row_index;
  }
  if ($view->id() == 'notes') {
    $unique_uuids = $new_view_result = [];
    $new_row_index = 0;
    // Loop through results and filter out duplicate results.
    foreach ($view->result as $row) { 
      // Use contact_id so this works for all entities
        $uuid = $row->nid;
        //\Drupal::logger('ra_custom_utility')->error(print_r($uuid, true));
        if (!in_array($uuid, $unique_uuids)) {
          $row->index = $new_row_index;
          $new_view_result[] = $row;
          $unique_uuids[] = $uuid;
          $new_row_index++;
        }
    }
	$view->result = $new_view_result;
    // Update the view index to match the new number of rows.
    $view->total_rows = $new_row_index;
     
  }
}
