<?php

namespace lenz\linkfield\listeners;

use craft\base\ElementInterface;
use craft\queue\BaseJob;
use lenz\linkfield\fields\LinkField;
use lenz\linkfield\models\element\ElementLinkType;
use lenz\linkfield\records\LinkRecord;

/**
 * Class CacheListenerJob
 */
class CacheListenerJob extends BaseJob
{
  /**
   * @var int
   */
  public $fieldId;


  /**
   * @inheritdoc
   */
  public function execute($queue) {
    $state = ElementListenerState::getInstance();
    $field = $this->getField();
    if (is_null($field)) {
      return;
    }

    $conditions = $state->getFieldElementLinkConditions($field->id);
    if (is_null($conditions)) {
      return;
    }

    LinkRecord::updateAll([
      'linkedTitle' => null,
      'linkedUrl'   => null,
    ], $conditions);

    if (!$field->enableElementCache) {
      return;
    }

    $linkTypes = $field->getEnabledLinkTypes();
    $elementTypes = $this->getElementMap($conditions, $linkTypes);
    if (is_null($elementTypes)) {
      return;
    }

    $index = 0;
    $total = 0;
    foreach ($elementTypes as $elementType => $sites) {
      foreach ($sites as $siteId => $elementIds) {
        $total += count($elementIds);
      }
    }

    /** @var ElementInterface $elementType */
    foreach ($elementTypes as $elementType => $sites) {
      foreach ($sites as $siteId => $elementIds) {
        $elements = $elementType::find()
          ->siteId($siteId)
          ->id($elementIds)
          ->all();

        foreach ($elements as $element) {
          ElementListener::updateElement($element);

          $index += 1;
          $this->setProgress($queue, $index / $total);
        }
      }
    }
  }


  // Protected methods
  // -----------------

  /**
   * @inheritdoc
   */
  protected function defaultDescription(): string {
    return \Craft::t('app', 'Cache {field} element links', [
      'field' => $this->getFieldName(),
    ]);
  }

  /**
   * @param array $conditions
   * @param array $linkTypes
   * @return array|null
   */
  protected function getElementMap(array $conditions, array $linkTypes) {
    $links  = LinkRecord::find()->where($conditions)->all();
    $result = [];

    foreach ($links as $link) {
      $linkType = array_key_exists($link->type, $linkTypes)
        ? $linkTypes[$link->type]
        : null;

      if (!($linkType instanceof ElementLinkType)) {
        continue;
      }

      $elementType = $linkType->elementType;
      $elementId   = $link->linkedId;
      $siteId      = is_null($link->linkedSiteId)
        ? $link->siteId
        : $link->linkedSiteId;

      if (is_null($siteId) || is_null($elementId)) {
        continue;
      }

      if (!isset($result[$elementType])) {
        $result[$elementType] = [];
      }

      if (!isset($result[$elementType][$siteId])) {
        $result[$elementType][$siteId] = [];
      }

      if (!in_array($elementId, $result[$elementType][$siteId])) {
        $result[$elementType][$siteId][] = $elementId;
      }
    }

    return $result;
  }

  /**
   * @return LinkField|null
   */
  protected function getField() {
    $field = \Craft::$app->getFields()->getFieldById($this->fieldId);
    return $field instanceof LinkField
      ? $field
      : null;
  }

  /**
   * @return string
   */
  protected function getFieldName() {
    $field = $this->getField();
    return is_null($field)
      ? '(unknown)'
      : $field->name;
  }


  // Static methods
  // --------------

  /**
   * @param LinkField $field
   */
  static function createForField(LinkField $field) {
    \Craft::$app->getQueue()->push(new CacheListenerJob([
      'description' => \Craft::t('app', 'Cache {field} element links', [
        'field' => $field->name,
      ]),
      'fieldId' => $field->id,
    ]));
  }
}
