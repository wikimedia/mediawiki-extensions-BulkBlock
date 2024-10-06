<?php

use MediaWiki\Block\BlockUser;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserNameUtils;

/**
 * Special page that allows the user to enter a list of usernames to block.
 */
class SpecialBulkBlock extends FormSpecialPage {

	private Language $language;
	private UserFactory $userFactory;
	private UserNameUtils $userNameUtils;

	public function __construct(
		Language $language,
		UserFactory $userFactory,
		UserNameUtils $userNameUtils
	) {
		parent::__construct( 'BulkBlock', 'bulkblock' );
		$this->language = $language;
		$this->userFactory = $userFactory;
		$this->userNameUtils = $userNameUtils;
	}

	/**
	 * @inheritDoc
	 */
	public function doesWrites(): bool {
		return true;
	}

	/**
	 * @inheritDoc
	 */
	protected function getDisplayFormat(): string {
		return 'ooui';
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName(): string {
		return 'users';
	}

	/**
	 * Get an HTMLForm descriptor array
	 * @return array[]
	 */
	protected function getFormFields(): array {
		return [
			'usernames' => [
				'type' => 'textarea',
				'label-message' => 'bulkblock-usernames',
				'rows' => 10,
				'required' => true,
				// Note: filter-callback is weird on HTMLForm::filter, it pretends it allows you
				// to process input into something else than just a string, but in reality the value
				// being later supplied to form inputs directly, thus you only can have it as a string
				//'filter-callback' => [ $this, 'preprocessUsernames' ],
				'validation-callback' => [ $this, 'validateUsernames' ],
				'default' => ''
			],
			'reason' => [
				'type' => 'text',
				'label-message' => 'bulkblock-reason',
				'required' => true,
				'default' => ''
			],
			'expiry' => [
				'type' => 'select',
				'label-message' => 'bulkblock-expiry',
				'required' => true,
				'options' => $this->language->getBlockDurations( false ),
				'default' => 'infinite'
			],
		];
	}

	/**
	 * Sets a custom form details
	 *
	 * This is only called when the form is actually visited directly
	 * @param HTMLForm $form
	 */
	protected function alterForm( HTMLForm $form ) {
		$form->setPreHtml( $this->msg( 'bulkblock-intro' )->parse() );
		$form->setId( 'bulkblock' );
		$form->setSubmitTextMsg( 'bulkblock-submit' );
		$form->setSubmitDestructive();
	}

	/**
	 * Process the form on POST submission.
	 * @param array $data
	 *
	 * @return bool
	 */
	public function onSubmit( array $data ): bool {
		return $this->handleFormSubmission( $data );
	}

	/** @inheritDoc */
	public function onSuccess() {
		$this->getOutput()->addWikiMsg( 'returnto', '[[' . SpecialPage::getTitleFor( 'BulkBlock' ) . ']]' );
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $par ) {
		parent::execute( $par );
		$this->getOutput()->setPageTitle( $this->msg( 'bulkblock' )->text() );
	}

	/**
	 * Handles the form submission, called as submit callback by HTMLForm
	 *
	 * @param array $formData
	 *
	 * @return bool
	 */
	public function handleFormSubmission( array $formData ): bool {
		$output = $this->getOutput();

		// Fetch form data submitted
		$usernames = $formData['usernames'];
		$reason = $formData['reason'];
		$expiry = $formData['expiry'];

		// Process the usernames list
		$usernames = $this->preprocessUsernames( $usernames );

		// Block the users.
		$successCount = 0;
		$errors = [];

		foreach ( $usernames as $username ) {
			$targetUser = $this->userFactory->newFromName( $username );

			// Block the user
			$blockResult = $this->doBlock( $targetUser, $reason, $expiry );
			if ( $blockResult !== true ) {
				$errors[] = $blockResult;
				continue;
			}

			// Log the block action.
			$logResult = $this->insertLog( $targetUser, $reason, $expiry );
			if ( $logResult !== true ) {
				$errors[] = $logResult;
				continue;
			}

			// Increment the success count.
			$successCount++;
		}

		// Show a success message if any
		if ( $successCount ) {
			$output->addHTML(
				Html::rawElement(
					'div',
					[ 'class' => 'successbox' ],
					$this->msg( 'bulkblock-success', $successCount )->escaped()
				)
			);
		}

		// Show an errors list if any
		// Note: the $form->formatErrors does not support parametrized messages,
		// so we have to use raw syntax
		if ( !empty( $errors ) ) {
			$output->addHTML(
				Html::rawElement(
					'div',
					[ 'class' => 'errorbox' ],
					$this->msg( 'bulkblock-errors' )->escaped() .
					Html::rawElement(
						'ul',
						[],
						Html::rawElement(
							'li',
							[],
							implode( '</li><li>', $errors )
						)
					)
				)
			);
		}

		return true;
	}

	/**
	 * Blocks target user
	 *
	 * @param User $targetUser
	 * @param string $reason
	 * @param string $expiry
	 *
	 * @return string|true
	 */
	private function doBlock( User $targetUser, string $reason, string $expiry ) {
		// Create a new block.
		$block = new DatabaseBlock();
		$block->setTarget( $targetUser );
		$block->setBlocker( $this->getUser() );
		$block->setReason( $reason );
		$block->setExpiry( BlockUser::parseExpiryInput( $expiry ) );

		// Save the block to the database.
		try {
			$blockResult = $block->insert();
		} catch ( MWException $e ) {
			return $this->msg( 'bulkblock-failed', $targetUser->getName() )->escaped();
		}

		// Unsuccessful block
		if ( $blockResult === false ) {
			return $this->msg( 'bulkblock-failed', $targetUser->getName() )->escaped();
		}

		return true;
	}

	/**
	 * Inserts a record into log
	 *
	 * @param User $targetUser
	 * @param string $reason
	 * @param string $expiry
	 *
	 * @return bool|string
	 */
	private function insertLog( User $targetUser, string $reason, string $expiry ) {
		$logEntry = new ManualLogEntry( 'block', 'block' );
		$logEntry->setPerformer( $this->getUser() );
		$logEntry->setTarget( $targetUser->getUserPage() );
		$logEntry->setComment( $reason );
		$logEntry->setParameters( [
			'5::duration' => wfIsInfinity( $expiry ) ? 'infinity' : $expiry,
			'6::flags' => ''
		] );
		try {
			$logId = $logEntry->insert();
		} catch ( MWException $e ) {
			return $this->msg( 'bulkblock-log-failed', $targetUser->getName() )->escaped();
		}
		$logEntry->publish( $logId );
		return true;
	}

	/**
	 * Processes the list of usernames, removing empty lines and comments.
	 * Converts into an array of strings
	 *
	 * @param string $usernamesStr
	 *
	 * @return string[] List of usernames
	 */
	public function preprocessUsernames( string $usernamesStr ): array {
		$usernames = trim( $usernamesStr );
		$usernames = explode( "\n", $usernames );
		$usernames = array_map( 'trim', $usernames );
		$usernames = array_map( 'ucfirst', $usernames );
		return $usernames;
	}

	/**
	 * Validates usernames data input
	 *
	 * @param string $usernames list of usernames as a string
	 * @param array $alldata all the form data
	 * @param HTMLForm $parent form object
	 *
	 * @return array|string|true
	 */
	public function validateUsernames( string $usernames, array $alldata, HTMLForm $parent ) {
		// HTMLForm logic is weird sometimes
		if ( !$parent->wasSubmitted() ) {
			return true;
		}

		$errors = [];
		$usernames = $this->preprocessUsernames( $usernames );

		// Check if there are any usernames to block.
		if ( empty( $usernames ) ) {
			return $this->msg( 'bulkblock-no-usernames' )->escaped();
		}

		// Validate out empty or invalid usernames.
		foreach ( $usernames as $username ) {

			// Check if the username is valid
			if ( !$this->userNameUtils->isValid( $username ) ) {
				$errors[] = $this->msg( 'bulkblock-invalid-username', $username )->escaped();
				continue;
			}

			// Check if the user does exist
			$targetUser = $this->userFactory->newFromName( $username );
			if ( !$targetUser->isRegistered() ) {
				$errors[] = $this->msg( 'bulkblock-non-existing-username', $username )->escaped();
				continue;
			}

			// Check if the user is already blocked.
			$blocked = DatabaseBlock::newFromTarget( $targetUser );
			if ( $blocked ) {
				$errors[] = $this->msg( 'bulkblock-already-blocked', $username )->escaped();
			}
		}

		if ( !empty( $errors ) ) {
			return $errors;
		}

		return true;
	}

}
