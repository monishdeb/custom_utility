<?php

/**
* @file
*/

namespace Drupal\ra_custom_utility\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Path\AliasManager;
use Drupal\Core\Url;
use Drupal\Core\Link;

/**
* Provides a 'Redirect URL list' block.
*
* @Block(
*   id = "ra_custom_utility_redirect",
*   admin_label = @Translation("Redirect URL list"),
*   category = @Translation("Display custom redirect URL list")
* )
*/
class RaCustomUtilityRedirectBlock extends BlockBase {

/**
* {@inheritdoc}
*/
public function build() {
$content = $this->ra_custom_utility_redirect_content();
return array(
'#markup' => $content,

);
}
/**
* {@inheritdoc}
*/
public function getCacheMaxAge() {
return 0;
}
/**
* {@inheritdoc}
*/

/*
* Fetching all the menus under the current term id irrespective of the levels
*/

public function ra_custom_utility_redirect_content() {
$output = '<table width="100%" border="0" cellspacing="0" cellpadding="0">
<thead>
<tr><th>Id</th>
    <th>Source</th>
    <th>Destination</th>
    <th>Action</th>
 
</tr>
</thead><tbody>';

		$query = db_select('redirect', 'redirect');
		$query->fields('redirect', array('rid','redirect_redirect__uri','redirect_source__path'));
		$result = $query->execute()->fetchAll();
		//dump($query->sqlQuery->__toString());
		$count = count($result);
		if($count){
			foreach ($result as $record) {
				$action = '<a href="/admin/config/search/redirect/edit/'.$record->rid.'">Edit</a> | ';
				$action .= '<a href="/admin/config/search/redirect/Delete/'.$record->rid.'">Delect</a>';
				$output .= "<tr><td>{$record->rid}</td><td>{$record->redirect_source__path}</td><td>{$record->redirect_redirect__uri}</td><td>{$action}</td></tr>";
			}
			
		}

$output .="</tbody></table>";
return $output;

}
}
