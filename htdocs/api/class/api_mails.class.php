<?php
/* Copyright (C) 2026       Jon Bendtsen        <jon.bendtsen.github@jonb.dk>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see https://www.gnu.org/licenses/.
 */

use Luracast\Restler\RestException;

require_once DOL_DOCUMENT_ROOT.'/api/class/api.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';

/**
 * API that allows sending emails programmatically.
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Mails extends DolibarrApi
{

    /**
     * @var array JSON Schema definition for the POST /mails/send endpoint.
     *            Used for documentation (GET /schema) and input validation.
     */
    const SCHEMA_SEND_ENDPOINT = array(
        'type' => 'object',
        'description' => 'Request payload for sending an email programmatically.',
        'required' => array('recipients'), 
        'properties' => array(

            // --- 1. Source Object (Context) ---
            'source_object' => array(
                'type' => 'object',
                'required' => false,
                'description' => 'The Dolibarr object acting as context for the email (e.g., an order or invoice).',
                'properties' => array(
                    'type' => array(
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Object type.',
                        'enum' => array(
                            'commande',            // commande/class/commande.class.php
                            'conferenceorboothattendee', // eventorganization/class/conferenceorboothattendee.class.php
                            'contact',             // contact/class/contact.class.php
                            'facture',             // compta/facture/class/facture.class.php
                            'fichinter',           // fichinter/class/fichinter.class.php
                            'invoice_supplier',    // fourn/class/fournisseur.facture.class.php
                            'member',              // adherents/class/adherent.class.php
                            'order_supplier',      // fourn/class/fournisseur.commande.class.php
                            'propal',              // comm/propal/class/propal.class.php
                            'societe',             // societe/class/societe.class.php
                            'subscription',        // adherents/class/subscription.class.php
                            'supplier_proposal',   // supplier_proposal/class/supplier_proposal.class.php
                        ),
                        'example' => 'commande'
                    ),
                    'id' => array(
                        'type' => 'integer',
                        'required' => true,
                        'description' => 'ID of the object.',
                        'minimum' => 1,
                        'example' => 123
                    )
                )
            ),

            // --- 2. Sender Configuration ---
            'from' => array(
                'type' => 'object',
                'required' => false,
                'description' => 'Sender (From) address.',
                'properties' => array(
                    'type' => array(
                        'type' => 'string',
                        'required' => true,
                        'enum' => array('user', 'company', 'profile', 'email'),
                        'description' => 'Type of sender.'
                    ),
                    'id' => array(
                        'type' => 'integer',
                        'description' => 'Sender profile ID. Required if type="profile".',
                        'example' => 5
                    ),
                    'name' => array(
                        'type' => 'string',
                        'description' => 'Display name. Required if type="email".',
                        'example' => 'Sales Department'
                    ),
                    'email' => array(
                        'type' => 'string',
                        'description' => 'Email address. Required if type="email".',
                        'example' => 'sales@example.com'
                    )
                ),
                'example' => array('type' => 'user')
            ),
            'reply_to' => array(
                'type' => 'object',
                'required' => false,
                'description' => 'Reply-To address. Same format as "from".',
                'properties' => array(
                    'type' => array(
                        'type' => 'string',
                        'required' => true,
                        'enum' => array('user', 'company', 'profile', 'email'),
                        'description' => 'Type of reply-to address.'
                    ),
                    'id' => array('type' => 'integer', 'description' => 'Sender profile ID. Required if type="profile".'),
                    'name' => array('type' => 'string', 'description' => 'Display name. Required if type="email".'),
                    'email' => array('type' => 'string', 'description' => 'Email address. Required if type="email".')
                ),
                'example' => array('type' => 'company')
            ),
            'errors_to' => array(
                'type' => 'object',
                'required' => false,
                'description' => 'Errors-To address (where bounces and delivery failures are sent). Same format as "from".',
                'properties' => array(
                    'type' => array(
                        'type' => 'string',
                        'required' => true,
                        'enum' => array('user', 'company', 'profile', 'email'),
                        'description' => 'Type of errors-to address.'
                    ),
                    'id' => array('type' => 'integer', 'description' => 'Sender profile ID. Required if type="profile".'),
                    'name' => array('type' => 'string', 'description' => 'Display name. Required if type="email".'),
                    'email' => array('type' => 'string', 'description' => 'Email address. Required if type="email".')
                ),
                'example' => array('type' => 'email', 'name' => 'Mail Errors', 'email' => 'postmaster@example.com')
            ),

            // --- 3. Content (Template vs Inline) ---
            'template_id' => array(
                'type' => 'integer',
                'required' => false,
                'description' => 'ID of the email template from c_email_templates table. If provided, subject/message are loaded from here unless overridden.',
                'example' => 45
            ),
            'subject' => array(
                'type' => 'string',
                'required' => false,
                'description' => 'Subject line. If provided, it replaces the subject from the template (if any). If no template is used, this is the only subject.'
            ),
            'message' => array(
                'type' => 'string',
                'required' => false,
                'description' => 'HTML message body. If provided, it replaces the content from the template (if any). If no template is used, this is the only message.'
            ),

            // --- 4. Recipients ---
            'recipients' => array(
                'type' => 'object',
                'required' => true,
                'description' => 'Lists of recipients. Supports company email, specific contact IDs, or contacts by contact type code (from c_contact_type dictionary).',
                'properties' => array(
                    'to' => array(
                        'type' => 'array',
                        'required' => true,
                        'minItems' => 1,
                        'description' => 'Primary recipients. At least one required.',
                        'items' => array(
                            'type' => 'object',
                            'required' => ['type'],
                            'properties' => array(
                                'type' => array(
                                    'type' => 'string',
                                    'required' => true,
                                    'enum' => array('thirdparty', 'contact', 'contact_type', 'email'),
                                    'description' => 'Recipient type.'
                                ),
                                'id' => array(
                                    'type' => 'integer',
                                    'description' => 'Contact ID (required if type="contact"). Thirdparty ID (REQUIRED if type="thirdparty" AND no source_object).',
                                    'example' => 873
                                ),
                                'code' => array(
                                    'type' => 'string',
                                    'description' => 'Contact type code (e.g., "BILLING", "SHIPPING"). Required if type="contact_type".',
                                    'example' => 'BILLING'
                                ),
                                'thirdparty_id' => array(
                                    'type' => 'integer',
                                    'description' => 'Thirdparty ID to search contacts on. REQUIRED if type="contact_type" AND no source_object. Optional otherwise.',
                                    'example' => 45
                                ),
                                'value' => array(
                                    'type' => 'string',
                                    'description' => 'Email address string. Required if type="email".',
                                    'example' => 'name@example.com'
                                )
                            ),
                            'examples' => array(
                                'with_source_object' => array(
                                    array('type' => 'thirdparty'),                       // Uses linked thirdparty
                                    array('type' => 'contact', 'id' => 873),              // Specific contact
                                    array('type' => 'contact_type', 'code' => 'BILLING')  // Billing contacts on linked thirdparty
                                ),
                                'without_source_object' => array(
                                    array('type' => 'thirdparty', 'id' => 45),             // Specific thirdparty #45
                                    array('type' => 'contact', 'id' => 873),               // Specific contact
                                    array('type' => 'contact_type', 'code' => 'BILLING', 'thirdparty_id' => 45) // Billing contacts on #45
                                )
                            )
                        )
                    ),
                    'cc' => array(
                        'type' => 'array',
                        'required' => false,
                        'description' => 'Carbon copy recipients. **Must use the exact same item format as the "to" field.** Each item is an object specifying type, id/code, or value.',
                        'items' => array(
                            'type' => 'object',
                            'required' => ['type'],
                            'properties' => array(
                                'type' => array('type' => 'string', 'enum' => array('thirdparty', 'contact', 'contact_type', 'email')),
                                'id' => array('type' => 'integer'),
                                'code' => array('type' => 'string'),
                                'thirdparty_id' => array('type' => 'integer'),
                                'value' => array('type' => 'string')
                            )
                        ),
                        'example' => array(
                            array('type' => 'contact', 'id' => 873),
                            array('type' => 'email', 'value' => 'manager@example.com')
                        )
                    ),
                    'bcc' => array(
                        'type' => 'array',
                        'required' => false,
                        'description' => 'Blind carbon copy recipients. **Must use the exact same item format as the "to" field.**',
                        'items' => array(
                            'type' => 'object',
                            'required' => ['type'],
                            'properties' => array(
                                'type' => array('type' => 'string', 'enum' => array('thirdparty', 'contact', 'contact_type', 'email')),
                                'id' => array('type' => 'integer'),
                                'code' => array('type' => 'string'),
                                'thirdparty_id' => array('type' => 'integer'),
                                'value' => array('type' => 'string')
                            )
                        ),
                        'example' => array(
                            array('type' => 'email', 'value' => 'archive@example.com')
                        )
                    )
                )
            ),

            // --- 5. Options ---
            'options' => array(
                'type' => 'object',
                'required' => false,
                'description' => 'Additional processing options.',
                'properties' => array(
                    'regenerate_documents' => array(
                        'type' => 'boolean',
                        'default' => false,
                        'description' => 'If true, forces regeneration of the PDF document attached to the source object before sending.'
                    ),
                    'delivery_receipt' => array(
                        'type' => 'boolean',
                        'default' => false,
                        'description' => 'Request a delivery receipt from the mail server.'
                    )
                )
            ),

            // --- 6. Trigger ---
            'trigger_name' => array(
                'type' => 'string',
                'required' => false,
                'description' => 'Name of the trigger to execute after successful sending (e.g., ORDER_SENTBYMAIL).',
                'example' => 'ORDER_SENTBYMAIL'
            )
        )
    );

    /**
     * Definition for the Recipient Specification item (used in schema items)
     * Note: In strict JSON Schema, you'd define this as $defs or a separate object.
     * Here we describe it in comments or use a helper method to validate.
     * 
     * Expected format for each item in 'to', 'cc', 'bcc':
     * {
     *   "type": "thirdparty" | "contact" | "contact_type" | "email",
     *   "id": <int> (if type is contact),
     *   "code": <string> (if type is contact_type)
     *   "value": <string> (if type is email)
     * }
     */

    /**
     * Constructor of the class
     */
    public function __construct()
    {
        global $db;
        $this->db = $db;
    }

    /**
     * Return JSON schema documenting the send endpoint.
     *
     * Useful for developers to understand the required payload structure.
     *
     * @url GET schema
     * 
     * @return  array   Schema information
     * @throws RestException 403
     */
    public function schema()
    {
        return self::SCHEMA_SEND_ENDPOINT;
    }

    // TODO: Implement 'send' method next
    /*
    public function send($request_data = null)
    {
        // Implementation will go here...
    }
    */
}