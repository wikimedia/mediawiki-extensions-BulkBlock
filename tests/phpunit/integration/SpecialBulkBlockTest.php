<?php

namespace MediaWiki\Extension\BulkBlock\Tests\Integration;

use MediaWikiIntegrationTestCase;
use MediaWiki\Block\DatabaseBlock;
use MediaWiki\User\UserFactory;
use MediaWiki\Extension\BulkBlock\SpecialBulkBlock;
use MediaWiki\MediaWikiServices;

class SpecialBulkBlockTest extends MediaWikiIntegrationTestCase {

    private UserFactory $userFactory;
    private SpecialBulkBlock $specialBulkBlock;

    protected function setUp(): void {
        parent::setUp();

        $this->userFactory = MediaWikiServices::getInstance()->getUserFactory();

        $language = $this->getLanguage();
        $userNameUtils = MediaWikiServices::getInstance()->getUserNameUtils();

        $this->specialBulkBlock = new SpecialBulkBlock( $language, $this->userFactory, $userNameUtils );

        $sysop = $this->getTestSysop();
        $this->setMwGlobals( [ 'wgUser' => $sysop ] );

        $this->specialBulkBlock->setOutput( $this->getTestOutput() );
    }

    private function createUser( string $name ) {
        $user = $this->userFactory->newFromName( $name );
        if ( !$user->isRegistered() ) {
            $user->addToDatabase();
        }
        return $user;
    }

    public function testPreprocessUsernames() {
        $input = "  alice\nBob  \n\ncharlie\n";
        $expected = [ 'Alice', 'Bob', 'Charlie' ];
        $this->assertEquals( $expected, $this->specialBulkBlock->preprocessUsernames( $input ) );
    }

    public function testValidateUsernamesEmpty() {
        $form = $this->createMock( \HTMLForm::class );
        $form->method('wasSubmitted')->willReturn(true);

        $result = $this->specialBulkBlock->validateUsernames( '', [], $form );
        $this->assertStringContainsString( 'bulkblock-no-usernames', $result );
    }

    public function testValidateUsernamesInvalidAndNonExisting() {
        $form = $this->createMock( \HTMLForm::class );
        $form->method('wasSubmitted')->willReturn(true);

        $this->createUser( 'ValidUser' );

        $input = "ValidUser\nInvalid@User\nNonExistentUser";
        $result = $this->specialBulkBlock->validateUsernames( $input, [], $form );

        $this->assertIsArray( $result );
        $this->assertStringContainsString( 'bulkblock-invalid-username', implode(' ', $result) );
        $this->assertStringContainsString( 'bulkblock-non-existing-username', implode(' ', $result) );
    }

    public function testValidateUsernamesAlreadyBlocked() {
        $form = $this->createMock( \HTMLForm::class );
        $form->method('wasSubmitted')->willReturn(true);

        $user = $this->createUser( 'BlockedUser' );

        $block = new DatabaseBlock();
        $block->setTarget( $user );
        $block->setBlocker( $this->getTestSysop() );
        $block->setReason( 'Testing' );
        $block->setExpiry( \MediaWiki\Block\BlockUser::parseExpiryInput( 'infinite' ) );
        $block->insert();

        $result = $this->specialBulkBlock->validateUsernames( 'BlockedUser', [], $form );
        $this->assertIsArray( $result );
        $this->assertStringContainsString( 'bulkblock-already-blocked', implode(' ', $result) );
    }

    public function testHandleFormSubmissionBlocksUsers() {
        $usernames = [ 'TestUser1', 'TestUser2' ];
        foreach ( $usernames as $name ) {
            $this->createUser( $name );
        }

        $formData = [
            'usernames' => implode( "\n", $usernames ),
            'reason' => 'Testing bulk block',
            'expiry' => '1 week',
        ];

        $this->specialBulkBlock->setOutput( $this->getTestOutput() );

        $result = $this->specialBulkBlock->handleFormSubmission( $formData );

        $this->assertTrue( $result );

        foreach ( $usernames as $name ) {
            $user = $this->userFactory->newFromName( $name );
            $this->assertTrue( $user->isBlocked(), "User $name should be blocked" );
        }

        $html = $this->specialBulkBlock->getOutput()->getHTML();
        $this->assertStringContainsString( 'bulkblock-success', $html );
    }

}