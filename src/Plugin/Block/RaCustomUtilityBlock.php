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
* Provides a 'Divrai Emet Category list and posting' block.
*
* @Block(
*   id = "ra_custom_utility",
*   admin_label = @Translation("Divrai Emet Category list and posting"),
*   category = @Translation("Display custom Divrai Emet Category list and posting")
* )
*/
class RaCustomUtilityBlock extends BlockBase {

/**
* {@inheritdoc}
*/
public function build() {
$content = $this->ra_custom_utility_content();
return array(
'#markup' => $content,

);
}

/**
* {@inheritdoc}
*/

/*
* Fetching all the menus under the current term id irrespective of the levels
*/

public function ra_custom_utility_content() {
$categories = array(
      'Civil Rights' => 'Civil Rights (incl. Racism)', 
      'Economic Justice' => 'Economic Justice', 
      'Environment' => 'Environment', 
      'Food Justice/Hunger' => 'Food Justice/Hunger', 
      'Gender/LGBTQ' => 'Gender/LGBTQ', 
      'Gun Violence Prevention' => 'Gun Violence Prevention', 
      'Hate Crimes' => 'Hate Crimes (e.g. Anti-Semitism, Islamophobia)', 
      'Health Care' => 'Health Care', 
      'Holocaust and Genocide' => 'Holocaust and Genocide (politics of memory)', 
      'Human Rights' => 'Human Rights (incl. Slavery, Human Trafficking)', 
      'Iran' => 'Iran', 
      'Israel' => 'Israel', 
      'Religious Pluralism' => 'Religious Pluralism (incl. Masorti, Interreligious Dialogue)', 
      'Other' => 'Other'
    );
$output = '';
foreach($categories as $keyCatVal => $category_value){
	if($category_value){
		$query = db_select('node_field_data', 'node');
		
		$query->innerJoin('node__field_de_name', 'dename', 'node.nid = dename.entity_id AND dename.revision_id = node.vid');
		$query->innerJoin('node__field_de_category', 'decat', 'node.nid = decat.entity_id AND decat.revision_id = node.vid');
		$query->condition('node.status',1);
		$query->allowRowCount = TRUE;
		$query->condition('decat.field_de_category_value',$keyCatVal);
		$query->fields('node', array('nid','title'));
		$query->fields('dename', array('field_de_name_value'));
		//$result = $query->execute();
		///echo "<br>".$keyCatVal."<br>";
		//dump($query->__toString());
		$result = $query->execute()->fetchAll();
		//dump($query->sqlQuery->__toString());
		$count = count($result);
		if($count){
			$category_value_id = preg_replace("/[^a-zA-Z]+/", "", $category_value);
			$output .= '<h2 id="'.$category_value_id.'">'.$category_value.'</h2><ul class="divrai-emet-catlist">';
			$full_name = '';
			foreach ($result as $record) {
				$full_name = $record->field_de_name_value;
				$options = ['absolute' => TRUE];
				$url_object = Url::fromRoute('entity.node.canonical', ['node' => $record->nid], $options)->toString();
				$output .= '<li><a href="'.$url_object.'" target="_blank">'.$record->title.'</a> by '.$full_name.'</li>';
			}
			$output .= '</ul>';
		}
	}
}

return $output;

}
}
