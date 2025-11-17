<?php
/**
 * Tests for CPT public visibility flags to prevent data leaks.
 *
 * @package Documentate
 */

class CustomPostTypesVisibilityTest extends WP_UnitTestCase {

    // Removed Task and Event CPTs in Documentate.

    public function test_documentate_kb_not_publicly_queryable() {
        do_action( 'init' );
        $pto = get_post_type_object( 'documentate_document' );
        $this->assertNotNull( $pto );
        $this->assertFalse( $pto->public );
        $this->assertFalse( $pto->publicly_queryable );
    }
}
