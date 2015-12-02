<?php
namespace Smartling\Base;

use Exception;

use Smartling\Bootstrap;
use Smartling\DbAl\WordpressContentEntities\EntityAbstract;
use Smartling\DbAl\WordpressContentEntities\MenuItemEntity;
use Smartling\DbAl\WordpressContentEntities\WidgetEntity;
use Smartling\Exception\BlogNotFoundException;
use Smartling\Exception\SmartlingDbException;
use Smartling\Exception\SmartlingExceptionAbstract;
use Smartling\Helpers\ArrayHelper;
use Smartling\Helpers\CommonLogMessagesTrait;
use Smartling\Helpers\WordpressContentTypeHelper;
use Smartling\Helpers\XmlEncoder;
use Smartling\Settings\ConfigurationProfileEntity;
use Smartling\Specific\SurveyMonkey\PrepareRelatedSMSpecificTrait;
use Smartling\Submissions\SubmissionEntity;

/**
 * Class SmartlingCore
 *
 * @package Smartling\Base
 */
class SmartlingCore extends SmartlingCoreAbstract {

	use SmartlingCoreTrait;

	use PrepareRelatedSMSpecificTrait;

	use CommonLogMessagesTrait;
	/**
	 * current mode to send data to Smartling
	 */
	const SEND_MODE = self::SEND_MODE_FILE;

	/**
	 * Updates target entity
	 *
	 * @param SubmissionEntity $submission
	 * @param EntityAbstract   $entity
	 *
	 * @throws BlogNotFoundException
	 */
	private function saveTargetEntity ( SubmissionEntity $submission, EntityAbstract $entity ) {
		$needBlogSwitch = $submission->getTargetBlogId() !== $this->getSiteHelper()->getCurrentBlogId();

		if ( $needBlogSwitch ) {
			$this->getSiteHelper()->switchBlogId( $submission->getTargetBlogId() );
		}

		$ioWrapper = $this->getContentIoFactory()->getMapper( $submission->getContentType() );

		$ioWrapper->set( $entity );

		if ( $needBlogSwitch ) {
			$this->getSiteHelper()->restoreBlogId();
		}
	}

	/**
	 * @param SubmissionEntity $submission
	 * @param EntityAbstract   $entity
	 * @param array            $meta
	 *
	 * @throws BlogNotFoundException
	 */
	private function setMetaForTargetEntity (
		SubmissionEntity $submission,
		EntityAbstract $entity,
		array $meta = [ ]
	) {
		$needBlogSwitch = $submission->getTargetBlogId() !== $this->getSiteHelper()->getCurrentBlogId();

		if ( $needBlogSwitch ) {
			$this->getSiteHelper()->switchBlogId( $submission->getTargetBlogId() );
		}

		foreach ( $meta as $key => $value ) {
			$entity->setMetaTag( $key, $value );
		}

		if ( $needBlogSwitch ) {
			$this->getSiteHelper()->restoreBlogId();
		}
	}

	/**
	 * @param SubmissionEntity $submission
	 *
	 * @return array
	 * @throws BlogNotFoundException
	 */
	private function getMetaForOriginalEntity ( SubmissionEntity $submission ) {
		$contentEntity = $this->readContentEntity( $submission );

		$needBlogSwitch = $submission->getSourceBlogId() !== $this->getSiteHelper()->getCurrentBlogId();

		if ( $needBlogSwitch ) {
			$this->getSiteHelper()->switchBlogId( $submission->getSourceBlogId() );
		}

		$originalMetadata = $contentEntity->getMetadata();

		if ( $needBlogSwitch ) {
			$this->getSiteHelper()->restoreBlogId();
		}

		return $originalMetadata;
	}

	/**
	 * @param SubmissionEntity $submission
	 *
	 * @return array
	 * @throws BlogNotFoundException
	 */
	private function getMetaForTargetEntity ( SubmissionEntity $submission ) {
		$contentEntity = $this->readTargetContentEntity( $submission );

		$needBlogSwitch = $submission->getTargetBlogId() !== $this->getSiteHelper()->getCurrentBlogId();

		if ( $needBlogSwitch ) {
			$this->getSiteHelper()->switchBlogId( $submission->getTargetBlogId() );
		}

		$meta = $contentEntity->getMetadata();

		if ( $needBlogSwitch ) {
			$this->getSiteHelper()->restoreBlogId();
		}

		return $meta;
	}


	/**
	 * @param SubmissionEntity $submission
	 * @param string           $contentType
	 * @param array            $accumulator
	 */
	private function processRelatedTerm ( SubmissionEntity $submission, $contentType, & $accumulator ) {
		$this->getLogger()->debug(
			vsprintf(
				'Searching for terms related to submission = \'%s\'',
				[
					$submission->getId(),
				]
			)
		);
		if (
			in_array( $contentType, WordpressContentTypeHelper::getSupportedTaxonomyTypes() )
			&& WordpressContentTypeHelper::CONTENT_TYPE_WIDGET !== $submission->getContentType()
		) {
			$terms = $this->getCustomMenuHelper()->getTerms( $submission, $contentType );

			if ( 0 < count( $terms ) ) {
				foreach ( $terms as $element ) {
					$this->getLogger()->debug(
						vsprintf(
							'Sending for translation term = \'%s\' id = \'%s\' related to submission = \'%s\'',
							[
								$element->taxonomy,
								$element->term_id,
								$submission->getId(),
							]
						)
					);
					$accumulator[ $contentType ][] = $this->translateAndGetTargetId(
						$element->taxonomy,
						$submission->getSourceBlogId(),
						$element->term_id,
						$submission->getTargetBlogId()
					);
				}
			}
		}
	}

	/**
	 * @param SubmissionEntity $submission
	 * @param string           $contentType
	 * @param array            $accumulator
	 *
	 * @throws BlogNotFoundException
	 */
	private function processRelatedMenu ( SubmissionEntity $submission, $contentType, &$accumulator ) {
		if ( WordpressContentTypeHelper::CONTENT_TYPE_NAV_MENU_ITEM === $contentType ) {
			$this->getLogger()->debug(
				vsprintf(
					'Searching for menuItems related to submission = \'%s\'',
					[
						$submission->getId(),
					]
				)
			);

			$ids = $this
				->getCustomMenuHelper()
				->getMenuItems(
					$submission->getSourceId(),
					$submission->getSourceBlogId()
				);

			/** @var MenuItemEntity $menuItem */
			foreach ( $ids as $menuItemEntity ) {

				$this->getLogger()->debug(
					vsprintf(
						'Sending for translation entity = \'%s\' id = \'%s\' related to submission = \'%s\'',
						[
							WordpressContentTypeHelper::CONTENT_TYPE_NAV_MENU_ITEM,
							$menuItemEntity->getPK(),
							$submission->getId(),
						]
					)
				);

				$menuItemSubmission = $this->fastSendForTranslation(
					WordpressContentTypeHelper::CONTENT_TYPE_NAV_MENU_ITEM,
					$submission->getSourceBlogId(),
					$menuItemEntity->getPK(),
					$submission->getTargetBlogId()
				);

				$originalMenuItemMeta = $this->getMetaForOriginalEntity( $menuItemSubmission );

				$originalMenuItemMeta = ArrayHelper::simplifyArray( $originalMenuItemMeta );

				if ( in_array( $originalMenuItemMeta['_menu_item_type'], [ 'taxonomy', 'post_type' ] ) ) {
					$this->getLogger()->debug(
						vsprintf(
							'Sending for translation object = \'%s\' related to \'%s\' related to submission = \'%s\'',
							[
								$originalMenuItemMeta['_menu_item_object'],
								WordpressContentTypeHelper::CONTENT_TYPE_NAV_MENU_ITEM,
								$menuItemEntity->getPK(),
							]
						)
					);
					$relatedObjectId = $this->translateAndGetTargetId(
						$originalMenuItemMeta['_menu_item_object'],
						$submission->getSourceBlogId(),
						(int) $originalMenuItemMeta['_menu_item_object_id'],
						$submission->getTargetBlogId()
					);

					$originalMenuItemMeta['_menu_item_object_id'] = $relatedObjectId;
				}

				$this->setMetaForTargetEntity(
					$menuItemSubmission,
					$this->readTargetContentEntity( $menuItemSubmission ),
					$originalMenuItemMeta
				);

				$accumulator[ WordpressContentTypeHelper::CONTENT_TYPE_NAV_MENU ][] = $menuItemSubmission->getTargetId();
			}
		}
	}

	private function processMenuRelatedToWidget ( SubmissionEntity $submission, $contentType ) {
		if (
			WordpressContentTypeHelper::CONTENT_TYPE_NAV_MENU === $contentType
			&& WordpressContentTypeHelper::CONTENT_TYPE_WIDGET === $submission->getContentType()
		) {
			$this->getLogger()->debug(
				vsprintf(
					'Searching for menu related to widget for submission = \'%s\'',
					[
						$submission->getId(),
					]
				)
			);
			$originalEntity = $this->readContentEntity( $submission );

			/**
			 * @var WidgetEntity $originalEntity
			 */
			$menuId = (int) $originalEntity->getSettings()['nav_menu'];

			if ( 0 !== $menuId ) {

				$this->getLogger()->debug(
					vsprintf(
						'Sending for translation menu related to widget id = \'%s\' related to submission = \'%s\'',
						[
							$originalEntity->getPK(),
							$submission->getId(),
						]
					)
				);

				$newMenuId = $this->translateAndGetTargetId(
					WordpressContentTypeHelper::CONTENT_TYPE_NAV_MENU,
					$submission->getSourceBlogId(),
					$menuId,
					$submission->getTargetBlogId()
				);

				/**
				 * @var WidgetEntity $targetContent
				 */
				$targetContent = $this->readTargetContentEntity( $submission );

				$settings             = $targetContent->getSettings();
				$settings['nav_menu'] = $newMenuId;
				$targetContent->setSettings( $settings );

				$this->saveTargetEntity( $submission, $targetContent );
			}

		}
	}

	private function processFeaturedImage ( SubmissionEntity $submission ) {
		$originalMetadata = $this->getMetaForOriginalEntity( $submission );
		$this->getLogger()->debug(
			vsprintf(
				'Searching for Featured Images related to submission = \'%s\'',
				[
					$submission->getId(),
				]
			)
		);
		if ( array_key_exists( '_thumbnail_id', $originalMetadata ) ) {

			if ( is_array( $originalMetadata['_thumbnail_id'] ) ) {
				$originalMetadata['_thumbnail_id'] = (int) reset( $originalMetadata['_thumbnail_id'] );
			}

			$targetEntity = $this->readTargetContentEntity( $submission );
			$this->getLogger()->debug(
				vsprintf(
					'Sending for translation Featured Image id = \'%s\' related to submission = \'%s\'',
					[
						$originalMetadata['_thumbnail_id'],
						$submission->getId(),
					]
				)
			);

			$attSubmission = $this->fastSendForTranslation(
				WordpressContentTypeHelper::CONTENT_TYPE_MEDIA_ATTACHMENT,
				$submission->getSourceBlogId(),
				$originalMetadata['_thumbnail_id'],
				$submission->getTargetBlogId()
			);

			$this->downloadTranslationBySubmission( $attSubmission );

			$this->setMetaForTargetEntity( $submission, $targetEntity,
				[ '_thumbnail_id' => $attSubmission->getTargetId() ] );
		}
	}

	/**
	 * @param SubmissionEntity $submission
	 *
	 * @throws BlogNotFoundException
	 */
	public function prepareRelatedSubmissions ( SubmissionEntity $submission ) {

		$this->getLogger()->info(
			vsprintf(
				'Searching for related content for submission = \'%s\' for translation',
				[
					$submission->getId(),
				]
			)
		);

		$originalEntity      = $this->readContentEntity( $submission );
		$relatedContentTypes = $originalEntity->getRelatedTypes();
		$accumulator         = [
			WordpressContentTypeHelper::CONTENT_TYPE_CATEGORY => [ ],
			WordpressContentTypeHelper::CONTENT_TYPE_POST_TAG => [ ],
		];

		try {
			if ( ! empty( $relatedContentTypes ) ) {

				foreach ( $relatedContentTypes as $contentType ) {
					// SM Specific
					$this->processMediaAttachedToWidgetSM( $submission, $contentType );
					$this->processTestimonialAttachedToWidgetSM( $submission, $contentType );
					$this->processTestimonialsAttachedToWidgetSM( $submission, $contentType );
					//Standard
					$this->processRelatedTerm( $submission, $contentType, $accumulator );
					$this->processRelatedMenu( $submission, $contentType, $accumulator );
					$this->processMenuRelatedToWidget( $submission, $contentType );
					$this->processFeaturedImage( $submission );
				}
			}

			if ( $submission->getContentType() !== WordpressContentTypeHelper::CONTENT_TYPE_NAV_MENU ) {
				$this->getSiteHelper()->switchBlogId( $submission->getTargetBlogId() );
				foreach ( $accumulator as $type => $ids ) {
					wp_set_post_terms( $submission->getTargetId(), $ids, $type );
				}
				$this->getSiteHelper()->restoreBlogId();
			} else {
				$this->getCustomMenuHelper()->assignMenuItemsToMenu(
					(int) $submission->getTargetId(),
					(int) $submission->getTargetBlogId(),
					$accumulator[ WordpressContentTypeHelper::CONTENT_TYPE_NAV_MENU ]
				);
			}
		} catch ( BlogNotFoundException $e ) {
			$message = vsprintf( 'Inconsistent multisite installation. %s', [ $e->getMessage() ] );
			$this->getLogger()->emergency( $message );

			throw $e;
		}
	}

	/**
	 * Sends data to smartling directly
	 *
	 * @param SubmissionEntity $submission
	 * @param string           $xmlFileContent
	 *
	 * @return bool
	 */
	protected function sendStream ( SubmissionEntity $submission, $xmlFileContent ) {
		return $this->getApiWrapper()->uploadContent( $submission, $xmlFileContent );
	}

	/**
	 * Sends data to smartling via temporary file
	 *
	 * @param SubmissionEntity $submission
	 * @param string           $xmlFileContent
	 *
	 * @return bool
	 */
	protected function sendFile ( SubmissionEntity $submission, $xmlFileContent ) {
		$tmp_file = tempnam( sys_get_temp_dir(), '_smartling_temp_' );

		file_put_contents( $tmp_file, $xmlFileContent );

		$result = $this->getApiWrapper()->uploadContent( $submission, '', $tmp_file );

		unlink( $tmp_file );

		return $result;
	}

	private function getContentIOWrapper ( SubmissionEntity $entity ) {
		return $this->getContentIoFactory()->getMapper( $entity->getContentType() );
	}

	/**
	 * Checks and updates submission with given ID
	 *
	 * @param $id
	 *
	 * @return array of error messages
	 */
	public function checkSubmissionById ( $id ) {
		$messages = [ ];

		try {
			$submission = $this->loadSubmissionEntityById( $id );

			$this->checkSubmissionByEntity( $submission );
		} catch ( SmartlingExceptionAbstract $e ) {
			$messages[] = $e->getMessage();
		} catch ( Exception $e ) {
			$messages[] = $e->getMessage();
		}

		return $messages;
	}

	/**
	 * Checks and updates given submission entity
	 *
	 * @param SubmissionEntity $submission
	 *
	 * @return array of error messages
	 */
	public function checkSubmissionByEntity ( SubmissionEntity $submission ) {
		$messages = [ ];

		try {
			$this->getLogger()->info( vsprintf( self::$MSG_CRON_CHECK, [
				$submission->getId(),
				$submission->getStatus(),
				$submission->getContentType(),
				$submission->getSourceBlogId(),
				$submission->getSourceId(),
				$submission->getTargetBlogId(),
				$submission->getTargetLocale(),
			] ) );

			$submission = $this->getApiWrapper()->getStatus( $submission );

			$this->getLogger()->info( vsprintf( self::$MSG_CRON_CHECK_RESULT, [
				$submission->getContentType(),
				$submission->getSourceBlogId(),
				$submission->getSourceId(),
				$submission->getTargetLocale(),
				$submission->getApprovedStringCount(),
				$submission->getCompletedStringCount(),
			] ) );


			$submission = $this->getSubmissionManager()->storeEntity( $submission );
		} catch ( SmartlingExceptionAbstract $e ) {
			$messages[] = $e->getMessage();
		} catch ( Exception $e ) {
			$messages[] = $e->getMessage();
		}

		return $messages;
	}

	/**
	 * @param $id
	 *
	 * @return mixed
	 * @throws SmartlingDbException
	 */
	private function loadSubmissionEntityById ( $id ) {
		$params = [
			'id' => $id,
		];

		$entities = $this->getSubmissionManager()->find( $params );

		if ( count( $entities ) > 0 ) {
			return reset( $entities );
		} else {
			$message = vsprintf( 'Requested SubmissionEntity with id=%s does not exist.', [ $id ] );

			$this->getLogger()->error( $message );
			throw new SmartlingDbException( $message );
		}
	}

	/**
	 * @param SubmissionEntity $entity
	 */
	public function checkEntityForDownload ( SubmissionEntity $entity ) {
		if ( 100 === $entity->getCompletionPercentage() ) {
			$this->getLogger()->info( vsprintf( self::$MSG_CRON_DOWNLOAD, [
				$entity->getId(),
				$entity->getStatus(),
				$entity->getContentType(),
				$entity->getSourceBlogId(),
				$entity->getSourceId(),
				$entity->getTargetBlogId(),
				$entity->getTargetLocale(),
			] ) );
			$this->downloadTranslationBySubmission( $entity );
		}
	}

	public function bulkCheckNewAndInProgress () {
		$entities = $this->getSubmissionManager()->find( [
				'status' => [
					SubmissionEntity::SUBMISSION_STATUS_NEW,
					SubmissionEntity::SUBMISSION_STATUS_IN_PROGRESS,
				],
			]
		);
		$this->getLogger()->info( vsprintf( self::$MSG_CRON_INITIAL_SUMMARY, [ count( $entities ) ] ) );
		foreach ( $entities as $entity ) {
			if ( $entity->getStatus() === SubmissionEntity::SUBMISSION_STATUS_NEW ) {
				$this->getLogger()->info( vsprintf( self::$MSG_CRON_SEND, [
					$entity->getId(),
					$entity->getStatus(),
					$entity->getContentType(),
					$entity->getSourceBlogId(),
					$entity->getSourceId(),
					$entity->getTargetBlogId(),
					$entity->getTargetLocale(),
				] ) );
				$this->sendForTranslationBySubmission( $entity );
			}
			if ( $entity->getStatus() === SubmissionEntity::SUBMISSION_STATUS_IN_PROGRESS ) {
				$this->checkSubmissionByEntity( $entity );
				$this->checkEntityForDownload( $entity );
			}
		}
	}

	/**
	 * @param array $items
	 *
	 * @return array
	 * @throws SmartlingDbException
	 */
	public function bulkCheckByIds ( array $items ) {
		$results = [ ];
		foreach ( $items as $item ) {
			/** @var SubmissionEntity $entity */
			try {
				$entity = $this->loadSubmissionEntityById( $item );
			} catch ( SmartlingDbException $e ) {
				$this->getLogger()->error( 'Requested submission that does not exist: ' . (int) $item );
				continue;
			}
			if ( $entity->getStatus() === SubmissionEntity::SUBMISSION_STATUS_IN_PROGRESS ) {
				$this->checkSubmissionByEntity( $entity );
				$this->checkEntityForDownload( $entity );
				$results[] = $entity;
			}
		}

		return $results;
	}

	/**
	 * @param ConfigurationProfileEntity $profile
	 *
	 * @return array
	 */
	public function getProjectLocales ( ConfigurationProfileEntity $profile ) {
		$cacheKey = 'profile.locales.' . $profile->getId();
		$cached   = $this->getCache()->get( $cacheKey );

		if ( false === $cached ) {
			$cached = $this->getApiWrapper()->getSupportedLocales( $profile );
			$this->getCache()->set( $cacheKey, $cached );
		}

		return $cached;
	}

	public function handleBadBlogId ( SubmissionEntity $submission ) {
		$profileMainId = $submission->getSourceBlogId();

		$profiles = $this->getSettingsManager()->findEntityByMainLocale( $profileMainId );
		if ( 0 < count( $profiles ) ) {

			$this->getLogger()->warning(
				vsprintf(
					'Found broken profile. Id:%s. Deactivating.',
					[
						$profileMainId,
					]
				)
			);

			/**
			 * @var ConfigurationProfileEntity $profile
			 */
			$profile = reset( $profiles );
			$profile->setIsActive( 0 );
			$this->getSettingsManager()->storeEntity( $profile );
		}
	}
}