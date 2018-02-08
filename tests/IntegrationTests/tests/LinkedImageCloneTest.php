<?php

namespace Smartling\Tests\IntegrationTests\tests;

use Smartling\Helpers\ArrayHelper;
use Smartling\Submissions\SubmissionEntity;
use Smartling\Tests\IntegrationTests\SmartlingUnitTestCaseAbstract;

class LinkedImageCloneTest extends SmartlingUnitTestCaseAbstract
{
    public function testCloneImage()
    {
        $imageId = $this->createAttachment();
        $postId = $this->createPost('page');
        set_post_thumbnail($postId, $imageId);
        $this->assertTrue(has_post_thumbnail($postId));

        $translationHelper = $this->getTranslationHelper();

        /**
         * @var SubmissionEntity $submission
         */
        $submission = $translationHelper->prepareSubmission('attachment', 1, $imageId, 2, true);
        $imageSubmissionId = $submission->getId();

        /**
         * Check submission status
         */
        $this->assertTrue(SubmissionEntity::SUBMISSION_STATUS_NEW === $submission->getStatus());
        $this->assertTrue(1 === $submission->getIsCloned());

        $this->executeUpload();

        $submissions = $this->getSubmissionManager()->find(
            [
                'content_type' => 'page',
                'is_cloned'    => 1,
            ]);
        $this->assertTrue(0 === count($submissions));

        $submissions = $this->getSubmissionManager()->find(
            [
                'content_type' => 'attachment',
                'is_cloned'    => 1,
            ]);
        $this->assertTrue(1 === count($submissions));
        $submission = ArrayHelper::first($submissions);
        /**
         * @var SubmissionEntity $submission
         */
        $this->assertTrue($imageSubmissionId === $submission->getId());
    }
}