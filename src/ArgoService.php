<?php

namespace Drupal\argo;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EditorialContentEntityBase;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Entity\Sql\TableMappingInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Interacts with Argo.
 */
class ArgoService implements ArgoServiceInterface {

  /**
   * The core entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private $entityTypeManager;

  /**
   * Entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  private $entityFieldManager;

  /**
   * Exporter.
   *
   * @var ContentEntityExport
   */
  private $contentEntityExport;

  /**
   * Translate.
   *
   * @var ContentEntityTranslate
   */
  private $contentEntityTranslate;

  /**
   * Moderation info.
   *
   * @var \Drupal\content_moderation\ModerationInformationInterface
   */
  private $moderationInfo;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  private $connection;

  /**
   * The service constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The core entity type manager service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The core entity field manager service.
   * @param ContentEntityExport $contentEntityExport
   *   Exporter.
   * @param ContentEntityTranslate $contentEntityTranslate
   *   Content entity translation service.
   * @param \Drupal\content_moderation\ModerationInformationInterface $moderationInfo
   *   Moderation info.
   * @param \Drupal\Core\Database\Connection $connection
   *   DB connection.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    EntityFieldManagerInterface $entityFieldManager,
    ContentEntityExport $contentEntityExport,
    ContentEntityTranslate $contentEntityTranslate,
    ModerationInformationInterface $moderationInfo,
    Connection $connection
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
    $this->contentEntityExport = $contentEntityExport;
    $this->contentEntityTranslate = $contentEntityTranslate;
    $this->moderationInfo = $moderationInfo;
    $this->connection = $connection;
  }

  /**
   * Export.
   *
   * @param string $entityType
   *   Entity type ID.
   * @param string $uuid
   *   Entity UUID.
   * @param int $revisionId
   *   (optional) Entity revision ID.
   *
   * @return array
   *   Export.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function export(string $entityType, string $uuid, int $revisionId = NULL) {
    $entity = $this->loadEntity($entityType, $uuid, $revisionId);
    return $this->contentEntityExport->export($entity);
  }

  /**
   * Translate.
   *
   * @param string $entityType
   *   Entity type ID.
   * @param string $uuid
   *   Entity UUID.
   * @param array $translation
   *   Translation object.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   * @throws \Drupal\Core\TypedData\Exception\ReadOnlyException
   * @throws \Drupal\typed_data\Exception\InvalidArgumentException
   */
  public function translate(string $entityType, string $uuid, array $translation) {
    $revisionId = $translation['revisionId'] ?? NULL;
    $entity = $this->loadEntity($entityType, $uuid, $revisionId);
    $translated = $this->contentEntityTranslate->translate($entity, $translation);

    // Paragraphs are never displayed on their own, and so we should not apply
    // moderation states to these entities.
    if ($translated instanceof EntityPublishedInterface && !($translated instanceof ParagraphInterface)) {
      $translated->setUnpublished();
    }
    if (isset($translation['stateId'])) {
      if ($translation['stateId'] === 'published') {
        $translated->setPublished();
      }
    }
    if ($this->moderationInfo->isModeratedEntity($translated)) {
      if (!isset($translation['stateId']) || strlen($translation['stateId']) < 1) {
        /** @var \Drupal\content_moderation\Plugin\WorkflowType\ContentModerationInterface $contentModeration */
        $contentModeration = $this->moderationInfo->getWorkflowForEntity($translated)->getTypePlugin();
        $stateId = $contentModeration->getInitialState($translated)->id();
      }
      else {
        $stateId = $translation['stateId'];
      }
      $translated->set('moderation_state', $stateId);
    }

    // Update changed time of the entity so we are not saving new revisions with
    // timestamps in the past.
    if ($translated instanceof EntityChangedInterface) {
      $translated->setChangedTime(time());
    }
    // Also update the author to reflect the Argo service account.
    if ($translated instanceof EntityOwnerInterface) {
      $current_user = \Drupal::currentUser();
      $translated->setOwnerId($current_user->id());
    }
    $translated->save();
  }

  /**
   * Loads an entity by its uuid or revision Id - if available.
   *
   * @param string $entityType
   *   Entity type ID.
   * @param string $uuid
   *   Entity UUID.
   * @param int|null $revisionId
   *   (optional) Entity revision ID.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface
   *   The loaded entity.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  private function loadEntity(string $entityType, string $uuid, int $revisionId = NULL) {
    // Unfortunately loading an entity by its uuid will only load the latest
    // "published" revision which could be different from the original entity.
    // Until Drupal core supports loading entity revisions by a uuid, we try and
    // load the entity by its revision id.
    // @see https://www.drupal.org/project/drupal/issues/1812202
    if (isset($revisionId)) {
      /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
      $entity = $this->entityTypeManager
        ->getStorage($entityType)
        ->loadRevision($revisionId);
    }
    else {
      // If the revision id is not available, we resort to the uuid. Some
      // entities might not support revisions.
      $loadResult = $this->entityTypeManager
        ->getStorage($entityType)
        ->loadByProperties(['uuid' => $uuid]);
      if (empty($loadResult)) {
        throw new MissingDataException();
      }
      /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
      $entity = $loadResult[array_keys($loadResult)[0]];
      // If there's no provided vid but the entity is revisionable,
      // get the latest vid and fetch the latest revision.
      if ($entity instanceof RevisionableInterface) {
        /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
        $storage = $this->entityTypeManager
          ->getStorage($entityType);
        $vid = $storage->getLatestRevisionId($entity->id());
        $entity = $storage->loadRevision($vid);
        if (is_null($entity)) {
          throw new MissingDataException();
        }
      }
    }

    return $entity;
  }

  /**
   * Get column name for a given table mapping and key.
   */
  private function getColumnName(TableMappingInterface $tableMapping, string $key) {
    return $tableMapping->getColumnNames($key)['value'];
  }

  /**
   * Get updated entities.
   *
   * @param string $entityType
   *   Editorial content entity type ID.
   * @param bool $onlyPublished
   *   If true, return only latest published revisions. Else return the latest revisions regardless of status.
   * @param int $lastUpdate
   *   UNIX timestamp of last update query.
   * @param int $limit
   *   Number of records to return.
   * @param int $offset
   *   Query offset.
   *
   * @return array
   *   Editorial content entities updated since $lastUpdate, or have no change timestamp.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityMalformedException
   * @throws \Exception
   *   If $entityType is not an editorial content entity.
   */
  public function getUpdated(string $entityType, bool $onlyPublished, int $lastUpdate, int $limit, int $offset) {
    /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $entityStorage */
    $entityStorage = $this->entityTypeManager
      ->getStorage($entityType);

    /** @var \Drupal\Core\Entity\ContentEntityTypeInterface $contentEntityType */
    $contentEntityType = $this->entityTypeManager->getDefinition($entityType);

    if (!$contentEntityType->entityClassImplements(EditorialContentEntityBase::class)) {
      throw new \Exception("\"{$contentEntityType->id()}\" is not an editorial content entity type");
    }

    $idKey = $contentEntityType->getKey('id');
    $revisionIdKey = $contentEntityType->getKey('revision');
    $publishedKey = $contentEntityType->getKey('published');
    $langcodeKey = $contentEntityType->getKey('langcode');

    $revisionTable = $entityStorage->getRevisionDataTable();

    /** @var \Drupal\Core\Entity\Sql\TableMappingInterface $tableMapping */
    $tableMapping = $entityStorage->getTableMapping();

    $idCol = $this->getColumnName($tableMapping, $idKey);
    $revisionIdCol = $this->getColumnName($tableMapping, $revisionIdKey);
    $publishedCol = $this->getColumnName($tableMapping, $publishedKey);
    $langcodeCol = $this->getColumnName($tableMapping, $langcodeKey);

    // EntityChangedTrait implies all editorial content entities have a "changed" field.
    $changedCol = 'changed';

    // Handmade query due to Entity Storage API adding unnecessary and slow joins.
    // Get all entity revision IDs of a given type changed since last update.

    $onlyPublishedFilter = '';
    if ($onlyPublished) {
      $onlyPublishedFilter = "AND {$publishedCol} = 1";
    }

    $results = $this->connection->query("
    WITH ranked_revision AS (
        SELECT {$revisionIdCol},
               ROW_NUMBER() OVER (PARTITION BY {$idCol} ORDER BY {$changedCol} DESC) AS rn
        FROM {$revisionTable} AS revision
        WHERE {$langcodeCol} = 'en-US'
          AND ({$changedCol} > :last_update OR {$changedCol} IS NULL)
          {$onlyPublishedFilter}
        GROUP BY {$idCol}, {$revisionIdCol}
    )
    SELECT {$revisionIdCol}
    FROM ranked_revision
    WHERE rn = 1
    ORDER BY {$revisionIdCol}; 
    ", ['last_update' => $lastUpdate])->fetchAll();

    $revisionIds = [];
    foreach ($results as $result) {
      $revisionIds[] = intval($result->$revisionIdKey);
    }

    $count = count($revisionIds);

    $revisionIds = array_slice($revisionIds, $offset, $limit);

    $nextOffset = $offset + $limit;
    $hasNext = $nextOffset < $count;

    $updated = [];
    if ($hasNext) {
      $updated['nextOffset'] = $nextOffset;
      $updated['count'] = $count;
    }

    $updated['data'] = [];
    $entities = $entityStorage->loadMultipleRevisions($revisionIds);
    /** @var \Drupal\Core\Entity\EditorialContentEntityBase $entity */
    foreach ($entities as $entity) {
      $changedTime = intval($entity->getChangedTime());
      $updated['data'][] = [
        'typeId' => $entity->getEntityTypeId(),
        'bundle' => $entity->bundle(),
        'id' => $entity->id(),
        'revisionId' => $entity->getRevisionId(),
        'uuid' => $entity->uuid(),
        'path' => $entity->toUrl()->toString(),
        'langcode' => $entity->language()->getId(),
        'changed' => $changedTime
      ];
    }

    return $updated;
  }

  /**
   * Updated query util.
   *
   * @param \Drupal\Core\Entity\EntityStorageInterface $entityStorage
   *   Entity storage.
   * @param int $lastUpdate
   *   Last update epoch seconds.
   * @param string $changedName
   *   Name of changed field.
   * @param string $langcodeKey
   *   Name of langcode field.
   * @param string $revisionCreatedKey
   *   Name of revision created field.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   Query.
   */
  private function updatedQuery(EntityStorageInterface $entityStorage,
                                $lastUpdate,
                                $changedName,
                                $langcodeKey,
                                $revisionCreatedKey) {
    $query = $entityStorage->getQuery();
    return $query
      ->condition($langcodeKey, Language::LANGCODE_NOT_SPECIFIED, '!=')
      ->condition($query->orConditionGroup()
        ->condition($changedName, $lastUpdate, '>')
        ->condition($revisionCreatedKey, $lastUpdate, '>'));
  }

  /**
   * Get deletion log.
   */
  public function getDeletionLog() {
    $conn = Database::getConnection();
    $deleted = $conn->query('SELECT * FROM {argo_entity_deletion}')->fetchAll();
    return ['deleted' => $deleted];
  }

  /**
   * Reset deletion log.
   *
   * @param array $deleted
   *   Deleted entity UUIDs to clear from log.
   */
  public function resetDeletionLog(array $deleted) {
    $ids = [];
    foreach ($deleted as $item) {
      $ids[] = $item['uuid'];
    }

    $conn = Database::getConnection();
    $conn->delete('argo_entity_deletion')->condition('uuid', $ids, 'IN')->execute();
  }

  /**
   * Get entity UUID & revision ID.
   */
  public function entityInfo($type, $id) {
    $entity = $this->entityTypeManager
      ->getStorage($type)
      ->load($id);

    return [
      'uuid' => $entity->uuid(),
      'revisionId' => $this->contentEntityExport->getRevisionId($entity)
    ];
  }

}
