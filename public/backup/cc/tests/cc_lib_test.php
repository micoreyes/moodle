<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Unit tests for IMS Common Cartridge library PHP 8.3 compatibility fixes.
 *
 * MDL-89023: IMS Common Cartridge export fails with PHP 8.3 when course
 * contains a Page activity.
 *
 * Fix 1 (cc_general.php): general_cc_file::on_create() now skips the 'xmlns'
 * key when iterating ccnamespaces to call createAttributeNS(). In PHP 8.3,
 * createAttributeNS() with an 'xmlns:*' qualified name throws a
 * DOMException ("Namespace Error") because the xmlns prefix is reserved by
 * the XML Namespaces specification.
 *
 * Fix 2 (cc_page.php): page11_resurce_file::on_create() now passes '' instead
 * of null as the $qualifiedName argument to DOMImplementation::createDocument().
 * Passing null is deprecated in PHP 8.3.
 *
 * @package   core
 * @category  test
 * @author     Karl Michael Reyes <michaelreyes@catalyst-ca.net>
 * @copyright  2026 Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \general_cc_file
 * @covers \page11_resurce_file
 */

namespace core;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/cc/cc_lib/cc_general.php');
require_once($CFG->dirroot . '/backup/cc/cc_lib/cc_page.php');
require_once($CFG->dirroot . '/backup/cc/cc_lib/cc_basiclti.php');

/**
 * Unit tests for MDL-89023: PHP 8.3 compatibility in the CC export library.
 *
 * @package   core
 * @category  test
 * @author     Karl Michael Reyes <michaelreyes@catalyst-ca.net>
 * @copyright  2026 Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cc_lib_test extends \advanced_testcase {
    /**
     * Test that page11_resurce_file can be instantiated without throwing a
     * DOMException on PHP 8.3.
     *
     * page11_resurce_file has only 'xmlns' in its ccnamespaces array. Before
     * the fix, general_cc_file::on_create() would call:
     *   createAttributeNS('http://www.w3.org/1999/xhtml', 'xmlns:dummy')
     * which throws DOMException("Namespace Error") in PHP 8.3 because the
     * 'xmlns' prefix is reserved. The fix skips the 'xmlns' key.
     *
     * This also exercises Fix 2: page11_resurce_file::on_create() passes ''
     * instead of null to DOMImplementation::createDocument(), avoiding the
     * PHP 8.3 deprecation for null arguments.
     *
     * @covers \page11_resurce_file::on_create
     * @covers \general_cc_file::on_create
     */
    public function test_page_resource_file_instantiation_does_not_throw(): void {
        // Construction triggers XMLGenericDocument::documentInit() -> on_create().
        // On PHP 8.3 without the fix, this would throw DOMException.
        $page = new \page11_resurce_file();
        $this->assertInstanceOf(\general_cc_file::class, $page);
    }

    /**
     * Test that page11_resurce_file produces a valid DOMDocument after instantiation.
     *
     * Verifies Fix 2: createDocument() with '' as qualifiedName correctly produces
     * a document whose root element is accessible and has the expected tag name.
     *
     * @covers \page11_resurce_file::on_create
     */
    public function test_page_resource_file_creates_valid_document(): void {
        $page = new \page11_resurce_file();

        $this->assertInstanceOf(\DOMDocument::class, $page->doc);
        $this->assertNotNull($page->doc->documentElement);
        $this->assertSame('html', $page->doc->documentElement->localName);
    }

    /**
     * Test that page11_resurce_file XML output is non-empty and contains the html root.
     *
     * @covers \page11_resurce_file::on_create
     * @covers \general_cc_file::on_create
     */
    public function test_page_resource_file_xml_output(): void {
        $page = new \page11_resurce_file();
        $xml = $page->viewXML();

        $this->assertNotEmpty($xml);
        $this->assertStringContainsString('<html', $xml);
        $this->assertStringContainsString('http://www.w3.org/1999/xhtml', $xml);
    }

    /**
     * Test that basicltil1_resurce_file (which has 'xmlns' as first key plus other
     * namespace keys) can be instantiated without DOMException on PHP 8.3.
     *
     * This verifies that the fix in general_cc_file::on_create() skips ONLY the
     * 'xmlns' key while still processing all other namespace keys (blti, lticm,
     * lticp, xsi) correctly.
     *
     * @covers \general_cc_file::on_create
     */
    public function test_basic_lti_resource_file_instantiation_does_not_throw(): void {
        // Basicltil1_resurce_file has: 'xmlns', 'blti', 'lticm', 'lticp', 'xsi'.
        // The 'xmlns' key must be skipped; the others must still be processed.
        $resource = new \basicltil1_resurce_file();
        $this->assertInstanceOf(\general_cc_file::class, $resource);
    }

    /**
     * Test that basicltil1_resurce_file XML output contains expected root element and
     * namespace declarations for non-xmlns namespaces.
     *
     * Confirms that skipping the 'xmlns' key does not prevent other namespace
     * keys from being registered — the root element should still carry the correct
     * namespace declarations.
     *
     * @covers \general_cc_file::on_create
     */
    public function test_basic_lti_resource_file_xml_output(): void {
        $resource = new \basicltil1_resurce_file();
        $xml = $resource->viewXML();

        $this->assertNotEmpty($xml);
        $this->assertStringContainsString('cartridge_basiclti_link', $xml);
        // Xsi namespace declaration should still be present (non-xmlns key).
        $this->assertStringContainsString('http://www.w3.org/2001/XMLSchema-instance', $xml);
    }

    /**
     * Test that instantiating page11_resurce_file does not produce an xmlns:dummy
     * attribute in the serialised XML output.
     *
     * createAttributeNS() is called but the returned attribute is never appended
     * to any element. When the 'xmlns' key is correctly skipped the call is never
     * made at all, so no xmlns:dummy artefact should appear in the output.
     *
     * @covers \general_cc_file::on_create
     */
    public function test_page_resource_file_no_xmlns_dummy_attribute(): void {
        $page = new \page11_resurce_file();
        $xml  = $page->viewXML();

        $this->assertStringNotContainsString('xmlns:dummy', $xml);
    }
}
