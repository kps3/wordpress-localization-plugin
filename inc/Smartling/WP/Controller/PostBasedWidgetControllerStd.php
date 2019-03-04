<?php

namespace Smartling\WP\Controller;

use Smartling\Base\SmartlingCore;
use Smartling\Bootstrap;
use Smartling\Exception\SmartlingDbException;
use Smartling\Helpers\ArrayHelper;
use Smartling\Helpers\CommonLogMessagesTrait;
use Smartling\Helpers\DiagnosticsHelper;
use Smartling\Helpers\SmartlingUserCapabilities;
use Smartling\Jobs\DownloadTranslationJob;
use Smartling\Queue\Queue;
use Smartling\Submissions\SubmissionEntity;
use Smartling\WP\WPAbstract;
use Smartling\WP\WPHookInterface;

/**
 * Class PostWidgetController
 * @package Smartling\WP\Controller
 */
class PostBasedWidgetControllerStd extends WPAbstract implements WPHookInterface
{
    use DetectContentChangeTrait;

    const WIDGET_NAME      = 'smartling_connector_widget';
    const WIDGET_DATA_NAME = 'smartling';
    const CONNECTOR_NONCE  = 'smartling_connector_nonce';

    protected $servedContentType = 'undefined';
    protected $needSave          = 'Need to have title';
    protected $noOriginalFound   = 'No original post found';
    protected $abilityNeeded     = 'edit_post';

    private $mutedTypes = [
        'attachment',
    ];

    private function isMuted()
    {
        return in_array($this->getServedContentType(), $this->mutedTypes, true);
    }

    /**
     * @return string
     */
    public function getAbilityNeeded()
    {
        return $this->abilityNeeded;
    }

    /**
     * @param string $abilityNeeded
     */
    public function setAbilityNeeded($abilityNeeded)
    {
        $this->abilityNeeded = $abilityNeeded;
    }


    /**
     * @return string
     */
    public function getServedContentType()
    {
        return $this->servedContentType;
    }

    /**
     * @param string $servedContentType
     */
    public function setServedContentType($servedContentType)
    {
        $this->servedContentType = $servedContentType;
    }

    /**
     * @return string
     */
    public function getNoOriginalFound()
    {
        return $this->noOriginalFound;
    }

    /**
     * @param string $noOriginalFound
     */
    public function setNoOriginalFound($noOriginalFound)
    {
        $this->noOriginalFound = $noOriginalFound;
    }

    use CommonLogMessagesTrait;


    public function ajaxDownloadHandler()
    {
        if (array_key_exists('submissionIds', $_POST)) {
            $submissionIds = explode(',', $_POST['submissionIds']);
            foreach ($submissionIds as $submissionId) {
                $this->getCore()->getQueue()->enqueue([$submissionId], Queue::QUEUE_NAME_DOWNLOAD_QUEUE);
            }
            $result = [];
            try {
                do_action(DownloadTranslationJob::JOB_HOOK_NAME);
                $result['status'] = 'SUCCESS';
            } catch (\Exception $e) {
                $result['status'] = 'FAIL';
            }
            echo json_encode($result);
            exit;
        }
    }

    /**
     * @inheritdoc
     */
    public function register()
    {
        if (!DiagnosticsHelper::isBlocked() && !$this->isMuted()) {
            add_action('add_meta_boxes', [$this, 'box']);
            add_action('save_post', [$this, 'save']);
            add_action('wp_ajax_' . 'smartling_force_download_handler', [$this, 'ajaxDownloadHandler']);
        }
    }

    /**
     * @var SmartlingCore
     */
    private $core;

    /**
     * @return SmartlingCore
     */
    private function getCore()
    {
        if (!($this->core instanceof SmartlingCore)) {
            $this->core = Bootstrap::getContainer()->get('entrypoint');
        }

        return $this->core;
    }

    /**
     * add_meta_boxes hook
     *
     * @param string $post_type
     */
    public function box($post_type)
    {
        $post_types = [$this->servedContentType];

        if (in_array($post_type, $post_types) &&
            current_user_can(SmartlingUserCapabilities::SMARTLING_CAPABILITY_WIDGET_CAP)
        ) {
            add_meta_box(
                self::WIDGET_NAME,
                __('Smartling Widget'),
                [$this, 'preView'],
                $post_type,
                'side',
                'high'
            );
        }
    }

    /**
     * @param $post
     */
    public function preView($post)
    {
        wp_nonce_field(self::WIDGET_NAME, self::CONNECTOR_NONCE);
        if ($post && $post->post_title && '' !== $post->post_title) {
            try {
                $eh = $this->getEntityHelper();
                $currentBlogId = $eh->getSiteHelper()->getCurrentBlogId();
                $profile = $eh->getSettingsManager()->findEntityByMainLocale($currentBlogId);

                if (0 < count($profile)) {
                    $submissions = $this->getManager()
                        ->find([
                                   'source_blog_id' => $currentBlogId,
                                   'source_id'      => $post->ID,
                                   'content_type'   => $this->servedContentType,
                               ]);

                    $this->view([
                                    'submissions' => $submissions,
                                    'post'        => $post,
                                    'profile'     => ArrayHelper::first($profile),
                                ]
                    );
                } else {
                    echo '<p>' . __('No suitable configuration profile found.') . '</p>';
                }


            } catch (SmartlingDbException $e) {
                $message = 'Failed to search for the original post. No source post found for blog %s, post %s. Hiding widget';
                $this->getLogger()
                    ->warning(
                        vsprintf($message, [
                            $this->getEntityHelper()
                                ->getSiteHelper()
                                ->getCurrentBlogId(),
                            $post->ID,
                        ])
                    );
                echo '<p>' . __($this->noOriginalFound) . '</p>';
            } catch (\Exception $e) {
                $this->getLogger()
                    ->error($e->getMessage() . '[' . $e->getFile() . ':' . $e->getLine() . ']');
            }
        } else {
            echo '<p>' . __($this->needSave) . '</p>';
        }
    }

    /**
     * @param $post_id
     *
     * @return bool
     */
    private function runValidation($post_id)
    {
        $this->getLogger()->debug(vsprintf('Validating post id = \'%s\' saving', [$post_id]));
        if (!array_key_exists(self::CONNECTOR_NONCE, $_POST)) {
            $this->getLogger()->debug(vsprintf('Validation failed: no nonce exists', []));

            return false;
        }

        $nonce = $_POST[self::CONNECTOR_NONCE];

        if (!wp_verify_nonce($nonce, self::WIDGET_NAME)) {
            $this->getLogger()->debug(vsprintf('Validation failed: invalid nonce exists', []));

            return false;
        }

        if (defined('DOING_AUTOSAVE') && true === DOING_AUTOSAVE) {
            $this->getLogger()->debug(vsprintf('Validation failed: that is just autosave.', []));

            return false;
        }

        return $this->isAllowedToSave($post_id);
    }

    /**
     * @param $post_id
     *
     * @return bool
     */
    protected function isAllowedToSave($post_id)
    {
        $result = current_user_can($this->getAbilityNeeded(), $post_id);

        if (false === $result) {
            $this->getLogger()
                ->debug(vsprintf('Validation failed: current user doesn\'t have enough rights save the post', []));
        }

        return $result;
    }

    /**
     * @param $post_id
     *
     * @return mixed
     */
    public function save($post_id)
    {
        remove_action('save_post', [$this, 'save']);
        if (!array_key_exists('post_type', $_POST)) {
            return;
        }

        if ($this->servedContentType === $_POST['post_type']) {

            $this->getLogger()->debug(
                vsprintf('Entering post save hook. post_id = \'%s\', blog_id = \'%s\'',
                         [
                             $post_id,
                             $this->getEntityHelper()->getSiteHelper()->getCurrentBlogId(),
                         ])
            );
            // Handle case when a revision is being saved. Get post_id by
            // revision id.
            if ($parent_id = wp_is_post_revision($post_id)) {
                $post_id = $parent_id;
            }

            $sourceBlog = $this->getEntityHelper()->getSiteHelper()->getCurrentBlogId();
            $originalId = (int)$post_id;
            $this->getLogger()->debug(vsprintf('Detecting changes for \'%s\' id=%d',[$this->servedContentType, $post_id]));
            $this->detectChange($sourceBlog, $originalId, $this->servedContentType);

            if (false === $this->runValidation($post_id)) {
                return $post_id;
            }
            $this->getLogger()->debug(vsprintf('Validation completed for \'%s\' id=%d',[$this->servedContentType, $post_id]));

            if (!array_key_exists(self::WIDGET_DATA_NAME, $_POST)) {
                $this->getLogger()
                    ->debug(vsprintf('Validation failed: no smartling info while saving. Ignoring', [$post_id]));

                return;
            }

            $data = $_POST[self::WIDGET_DATA_NAME];

            $this->getLogger()->debug(vsprintf('got POST data: %s',[var_export($_POST, true)]));

            if (null !== $data && array_key_exists('locales', $data)) {
                $locales = [];
                if (array_key_exists('locales', $data)) {
                    if (is_array($data['locales'])) {
                        foreach ($data['locales'] as $_locale) {
                            if (array_key_exists('enabled', $_locale) && 'on' === $_locale['enabled']) {
                                $locales[] = (int)$_locale['blog'];
                            }
                        }
                    } elseif (is_string($data['locales'])) {
                        $locales = explode(',', $data['locales']);
                    } else {
                        return;
                    }
                }
                $this->getLogger()->debug(vsprintf('Finished parsing locales: %s',[var_export($locales, true)]));
                $core = $this->getCore();
                $translationHelper = $core->getTranslationHelper();
                if (array_key_exists('sub', $_POST) && count($locales) > 0) {
                    switch ($_POST['sub']) {
                        case 'Upload':
                            $this->getLogger()->debug('Upload case detected.');
                            if (0 < count($locales)) {
                                $wrapper = $this->getCore()->getApiWrapper();
                                $profiles = $this->getProfiles();

                                if (empty($profiles)) {
                                    $this->getLogger()->error('No suitable configuration profile found.');

                                    return;
                                }
                                $this->getLogger()->debug(vsprintf('Retrieving batch for jobId=%s', [$data['jobId']]));

                                try {
                                    $batchUid = $wrapper->retrieveBatch(ArrayHelper::first($profiles), $data['jobId'],
                                        'true' === $data['authorize'], [
                                            'name' => $data['jobName'],
                                            'description' => $data['jobDescription'],
                                            'dueDate' => [
                                                'date' => $data['jobDueDate'],
                                                'timezone' => $data['timezone'],
                                            ],
                                        ]);
                                } catch (\Exception $e) {
                                    $this
                                        ->getLogger()
                                        ->error(
                                            vsprintf(
                                                'Failed retrieving batch for job %s. Translation aborted.',
                                                [
                                                    var_export($_POST['jobId'], true)
                                                ]
                                            )
                                        );
                                    return;
                                }

                                foreach ($locales as $blogId) {
                                    $submission = $translationHelper->tryPrepareRelatedContent($this->servedContentType, $sourceBlog, $originalId, (int)$blogId, $batchUid, false);

                                    if (0 < $submission->getId()) {
                                        $submission->setStatus(SubmissionEntity::SUBMISSION_STATUS_NEW);
                                        $submission->setBatchUid($batchUid);
                                        $submission = $core->getSubmissionManager()->storeEntity($submission);
                                    }

                                    $this->getLogger()->info(
                                        vsprintf(
                                            static::$MSG_UPLOAD_ENQUEUE_ENTITY_JOB,
                                            [
                                                $this->servedContentType,
                                                $sourceBlog,
                                                $originalId,
                                                (int)$blogId,
                                                $submission->getTargetLocale(),
                                                $data['jobId'],
                                                $submission->getBatchUid(),
                                            ]
                                        ));
                                }

                                /**
                                 * $this->getLogger()->debug('Triggering Upload Job.');
                                 * do_action(UploadJob::JOB_HOOK_NAME);
                                 */

                            } else {
                                $this->getLogger()->debug('No locales found.');
                            }
                            break;
                        case 'Download':
                            foreach ($locales as $targetBlogId) {
                                $submissions = $this->getManager()
                                    ->find(
                                        [
                                            'source_id'      => $originalId,
                                            'source_blog_id' => $sourceBlog,
                                            'content_type'   => $this->servedContentType,
                                            'target_blog_id' => $targetBlogId,
                                        ]
                                    );

                                if (0 < count($submissions)) {
                                    $submission = ArrayHelper::first($submissions);

                                    $this->getLogger()
                                        ->info(
                                            vsprintf(
                                                static::$MSG_DOWNLOAD_ENQUEUE_ENTITY,
                                                [
                                                    $submission->getId(),
                                                    $submission->getStatus(),
                                                    $this->servedContentType,
                                                    $sourceBlog,
                                                    $originalId,
                                                    $submission->getTargetBlogId(),
                                                    $submission->getTargetLocale(),
                                                ]
                                            )
                                        );

                                    $core->getQueue()
                                        ->enqueue([$submission->getId()], Queue::QUEUE_NAME_DOWNLOAD_QUEUE);
                                }
                            }
                            $this->getLogger()->debug(vsprintf('Initiating Download Job', []));
                            do_action(DownloadTranslationJob::JOB_HOOK_NAME);
                            break;
                        default:
                            $this->getLogger()->debug(vsprintf('got Unknown action: \'%s\'',[$_POST['sub']]));
                    }
                } else {
                    $this->getLogger()->debug('No smartling action found.');
                }
            } else {
                $this->getLogger()->debug('Seems that no data to process.');
            }
            add_action('save_post', [$this, 'save']);
        }
    }
}
