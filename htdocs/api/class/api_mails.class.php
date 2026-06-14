<?php
/* Copyright (C) 2026	   Jon Bendtsen		<jon.bendtsen.github@jonb.dk>
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
require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';

/**
 * API that allows sending emails programmatically.
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Mails extends DolibarrApi
{
    private const TRACKID_LENGTH = 16;

    /**
	 * Map of $element values to their corresponding Class File paths.
	 * Key: The $element string used in the API (e.g., 'commande').
	 * Value: The absolute path to the .class.php file.
	 */
	const OBJECT_PATH_MAP = array(
		'commande'				  => DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php',
		'conferenceorboothattendee' => DOL_DOCUMENT_ROOT.'/eventorganization/class/conferenceorboothattendee.class.php',
		'contact'				   => DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php',
		'facture'				   => DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php',
		'invoice_supplier'		  => DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php',
		'member'					=> DOL_DOCUMENT_ROOT.'/adherents/class/adherent.class.php',
		'order_supplier'			=> DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php',
		'propal'					=> DOL_DOCUMENT_ROOT.'/comm/propal/class/propal.class.php',
		'societe'				   => DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php',
		'subscription'			  => DOL_DOCUMENT_ROOT.'/adherents/class/subscription.class.php',
		'supplier_proposal'		 => DOL_DOCUMENT_ROOT.'/supplier_proposal/class/supplier_proposal.class.php',
	);

    const ELEMENT_RIGHTS_MAP = array(
        'commande'              => array('module' => 'commande', 'right' => 'lire'),
        'conferenceorboothattendee' => array('module' => 'projet', 'right' => 'lire'),
        'contact'               => array('module' => 'societe', 'sub' => 'contact', 'right' => 'lire'),
        'facture'               => array('module' => 'facture', 'right' => 'lire'),
        'invoice_supplier'      => array('module' => 'fournisseur', 'sub' => 'facture', 'right' => 'lire'),
        'member'                => array('module' => 'adherent', 'right' => 'lire'),
        'order_supplier'        => array('module' => 'fournisseur', 'sub' => 'commande', 'right' => 'lire'),
        'propal'                => array('module' => 'propale', 'right' => 'lire'),
        'societe'               => array('module' => 'societe', 'right' => 'lire'),
        'subscription'          => array('module' => 'adherent', 'right' => 'lire'),
        'supplier_proposal'     => array('module' => 'supplier_proposal', 'right' => 'lire'),
    );

    /**
     * Map of object elements to their default email send triggers.
     */
    const TRIGGER_MAP = array(
        'commande' => 'ORDER_SENTBYMAIL',
        'facture' => 'BILL_SENTBYMAIL',
        'propal' => 'PROPAL_SENTBYMAIL',
        'order_supplier' => 'SUPPLIER_ORDER_SENTBYMAIL',
        'invoice_supplier' => 'SUPPLIER_INVOICE_SENTBYMAIL',
        'supplier_proposal' => 'SUPPLIER_PROPOSAL_SENTBYMAIL',
        // Add others as needed
    );

	/**
	 * @var array JSON Schema definition for the POST /mails/send endpoint.
	 *			Used for documentation (GET /schema) and input validation.
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
							'commande',			// commande/class/commande.class.php
							'conferenceorboothattendee', // eventorganization/class/conferenceorboothattendee.class.php
							'contact',			 // contact/class/contact.class.php
							'facture',			 // compta/facture/class/facture.class.php
							'invoice_supplier',	// fourn/class/fournisseur.facture.class.php
							'member',			  // adherents/class/adherent.class.php
							'order_supplier',	  // fourn/class/fournisseur.commande.class.php
							'propal',			  // comm/propal/class/propal.class.php
							'societe',			 // societe/class/societe.class.php
							'subscription',		// adherents/class/subscription.class.php
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
						'enum' => array('user', 'company', 'profile'),
						'description' => 'Type of sender.'
					),
					'id' => array(
						'type' => 'integer',
						'description' => 'Sender profile ID. Required if type="profile".',
						'example' => 5
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
						'enum' => array('user', 'company', 'profile'),
						'description' => 'Type of reply-to address.'
					),
					'id' => array('type' => 'integer', 'description' => 'Sender profile ID. Required if type="profile".')
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
						'enum' => array('user', 'company', 'profile'),
						'description' => 'Type of errors-to address.'
					),
					'id' => array('type' => 'integer', 'description' => 'Sender profile ID. Required if type="profile".')
				),
				'example' => array('type' => 'user', 'id' => 5)
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
                'description' => 'Lists of recipients from existing Dolibarr records (thirdparties, contacts, or contact types). Arbitrary email addresses are not supported.',
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
									'enum' => array('thirdparty', 'contact', 'contact_type'),
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
								)
							),
							'examples' => array(
								'with_source_object' => array(
									array('type' => 'thirdparty'),					   // Uses linked thirdparty
									array('type' => 'contact', 'id' => 873),			  // Specific contact
									array('type' => 'contact_type', 'code' => 'BILLING')  // Billing contacts on linked thirdparty
								),
								'without_source_object' => array(
									array('type' => 'thirdparty', 'id' => 45),			 // Specific thirdparty #45
									array('type' => 'contact', 'id' => 873),			   // Specific contact
									array('type' => 'contact_type', 'code' => 'BILLING', 'thirdparty_id' => 45) // Billing contacts on #45
								)
							)
						)
					),
					'cc' => array(
						'type' => 'array',
						'required' => false,
						'description' => 'Carbon copy recipients. **Must use the exact same item format as the "to" field.** Each item is an object specifying type, id/code.',
						'items' => array(
							'type' => 'object',
							'required' => ['type'],
							'properties' => array(
								'type' => array('type' => 'string', 'enum' => array('thirdparty', 'contact', 'contact_type')),
								'id' => array('type' => 'integer'),
								'code' => array('type' => 'string'),
								'thirdparty_id' => array('type' => 'integer')
							)
						),
						'example' => array(
							array('type' => 'contact', 'id' => 873),
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
								'type' => array('type' => 'string', 'enum' => array('thirdparty', 'contact', 'contact_type')),
								'id' => array('type' => 'integer'),
								'code' => array('type' => 'string'),
								'thirdparty_id' => array('type' => 'integer')							)
						),
						'example' => array(
							array('type' => 'thirdparty', 'id' => 873),
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

	/**
	 * Send an email via API.
	 *
	 * @param   array   $request_data   Request data payload
	 * @return  array				   Success response
	 *
	 * @url POST send
	 *
	 * @throws RestException 400 Bad Request (validation error)
	 * @throws RestException 403 Forbidden (permissions error)
	 * @throws RestException 404 Not Found (object/template not found)
	 * @throws RestException 500 Internal Server Error (SMTP failure)
	 */
	public function postSend($request_data = null)
	{
		global $conf, $langs, $user;

        // Restrict to internal users only
        if (!empty($user->socid) || !empty($user->contact_id) || !empty($user->fk_member)) {
            throw new RestException(403, "Access denied: This endpoint is restricted to internal users.");
        }

		if (!is_array($request_data)) {
			throw new RestException(400, 'Request data must be a valid JSON object/array.');
		}

        // --- 1. Load Source Object (Context) ---
        $sourceType = $request_data['source_object']['type'] ?? '';
        $sourceId = !empty($request_data['source_object']['id']) ? (int) $request_data['source_object']['id'] : 0;
        $object = $this->_loadSourceObject($sourceType, $sourceId);

		// Get Thirdparty if available
		$soc = null;
		if ($object && method_exists($object, 'fetch_thirdparty')) {
			$object->fetch_thirdparty();
			if (is_object($object->thirdparty) && $object->thirdparty->id > 0) {
				$soc = $object->thirdparty;
			}
		}

        // --- 2. Resolve Content (Subject/Message) ---
        $templateId = !empty($request_data['template_id']) ? (int) $request_data['template_id'] : 0;
        $inlineSubject = $request_data['subject'] ?? null;
        $inlineMessage = $request_data['message'] ?? null;
        $content = $this->_resolveContent($templateId, $inlineSubject, $inlineMessage);

		// --- 3. Resolve Sender Addresses ---
        $senderConfig = $request_data['from'] ?? null;
        $replyToConfig = $request_data['reply_to'] ?? null;
        $errorsToConfig = $request_data['errors_to'] ?? null;
        $templateEmailFrom = $content['template_email_from'] ?? '';
        $senderInfo = $this->_resolveSender($senderConfig, $replyToConfig, $errorsToConfig, $templateEmailFrom);

		// --- 4. Resolve Recipients ---
		$recipientsConfig = $request_data['recipients'] ?? [];
		$recipientList = $this->_resolveRecipients($recipientsConfig, $soc, $object);

		// --- 5. Prepare Attachments ---
		$shouldRegenerate = !empty($request_data['options']['regenerate_documents']);
		$attachments = $this->_prepareAttachments($object, $shouldRegenerate);

		// --- 6. Apply Substitutions ---
		$finalSubject = $this->_applySubstitutions($content['subject'], $object, $senderInfo);
		$finalMessage = $this->_applySubstitutions($content['message'], $object, $senderInfo);

		// --- 7. Send Email ---
		$result = $this->_sendEmail(
			$finalSubject,
			$finalMessage,
			$senderInfo,
			$recipientList,
			$attachments,
			$request_data['options']['delivery_receipt'] ?? false
		);

		// --- 8. Execute Auto-Detected Trigger ---
		// Only execute if a source object was provided and has a matching trigger
		if ($object && isset(self::TRIGGER_MAP[$object->element])) {
			$this->_executeTrigger(self::TRIGGER_MAP[$object->element], $object);
		}

		return [
			'success' => [
				'code' => 200,
				'message' => 'Email sent successfully',
				'trackid' => $result['trackid'],
				'msgid' => $result['msgid'] ?? ''
			]
		];
	}

    /**
     * Check if the current user has read access to a given object element type.
     *
     * Uses the ELEMENT_RIGHTS_MAP to determine the correct rights path in $user->rights.
     * Supports both simple permissions (e.g., $user->rights->commande->lire)
     * and nested permissions (e.g., $user->rights->fournisseur->commande->lire).
     * Super-admins ($user->admin) bypass this check.
     * Unknown element types are denied by default for security.
     *
     * @param string $elementType  The object element type (e.g., 'commande', 'propal', 'societe')
     * @return bool                True if user has read permission, false otherwise
     * @throws RestException(403) Thrown by caller if permission check fails (not thrown here)
     */
    private function _checkReadPermission($elementType)
    {
        global $user;

        // Super-admins always have access
        if (!empty($user->admin)) {
            return true;
        }
        // External users (socid set) usually have limited context but we trust the session
        // if they are accessing their own entity. For strict API security, we might still check.

        if (!isset(self::ELEMENT_RIGHTS_MAP[$elementType])) {
            // Unknown element type: Deny by default for security
            dol_syslog("No permission map entry for element type: {$elementType}", LOG_WARNING);
            return false;
        }

        $perm = self::ELEMENT_RIGHTS_MAP[$elementType];
        $hasRight = false;

        // Construct the rights path dynamically
        // Case 1: Nested (e.g., $user->rights->fournisseur->commande->lire)
        if (!empty($perm['sub'])) {
            if (
                isset($user->rights->{$perm['module']}) &&
                isset($user->rights->{$perm['module']}->{$perm['sub']}) &&
                isset($user->rights->{$perm['module']}->{$perm['sub']}->{$perm['right']})
            ) {
                $hasRight = ($user->rights->{$perm['module']}->{$perm['sub']}->{$perm['right']} > 0);
            }
        }
        // Case 2: Simple (e.g., $user->rights->commande->lire)
        else {
            if (
                isset($user->rights->{$perm['module']}) &&
                isset($user->rights->{$perm['module']}->{$perm['right']})
            ) {
                $hasRight = ($user->rights->{$perm['module']}->{$perm['right']} > 0);
            }
        }

        return $hasRight;
    }

    /**
     * Load a source object based on its type and ID.
     *
     * Dynamically includes the class file and fetches the record from the database.
     *
     * @param string|null $objectType   Type identifier (e.g., 'societe', 'contact')
     * @param int|null    $objectId     Numeric ID of the object to load
     * @return object|null              Loaded Dolibarr object instance, or null if no ID/type provided
     * @throws RestException(400) If object type is not supported or ID is missing/invalid
     * @throws RestException(404) If the object with the given ID does not exist in the database
     * @throws RestException(500) If the class file is missing or the class cannot be instantiated
     */
    private function _loadSourceObject(?string $objectType = null, ?int $objectId = null)
    {
        if (empty($objectType)) {
            return null;
        }
        if (!is_int($objectId) || $objectId < 1) {
            throw new RestException(400, "Invalid object ID. Must be a positive integer > 0.");
        }

        if (!isset(self::OBJECT_PATH_MAP[$objectType])) {
            throw new RestException(400, "Unsupported object type: '{$objectType}'.");
        }

        if (!$this->_checkReadPermission($objectType)) {
            throw new RestException(403, "Access denied: You do not have permission to read '{$objectType}' objects.");
        }

        $filePath = self::OBJECT_PATH_MAP[$objectType];
        if (!file_exists($filePath)) {
            dol_syslog("Class file not found for {$objectType} at {$filePath}.", LOG_ERROR);
            throw new RestException(500, "Configuration error: Missing class file for '{$objectType}'.");
        }

        require_once $filePath;
        $fileName = basename($filePath);
        $className = str_replace('.class.php', '', $fileName);

        if (!class_exists($className)) {
            throw new RestException(500, "Class '{$className}' could not be loaded.");
        }

        $object = new $className($this->db);
        if ($object->fetch($objectId) <= 0) {
            throw new RestException(404, "Object '{$objectType}' with ID {$objectId} not found.");
        }

        return $object;
    }

    /**
     * Load content from template or use inline values.
     *
     * Handles priority: If 'template_id' is provided, loads subject/message from DB.
     * Inline 'subject' or 'message' values override template values when provided.
     * A missing key (null) preserves the template value. An explicit empty string ("") clears the value.
     *
     * @param int|null    $templateId      Template ID to load from database (0 or null to skip)
     * @param string|null $inlineSubject   Subject from request (null if key missing, "" if key present but empty, or string value)
     * @param string|null $inlineMessage   Message from request (null if key missing, "" if key present but empty, or string value)
     * @return array                      Array with keys:
     *                                    - 'subject': Final email subject string (trimmed)
     *                                    - 'message': Final email message body string (trimmed)
     *                                    - 'template_email_from': Email address from the loaded template (empty string if none)
     * @throws RestException(400) If required fields are missing (no template, no inline data) or final content is empty.
     * @throws RestException(404) If the specified template ID does not exist.
     */
    private function _resolveContent($templateId, $inlineSubject, $inlineMessage)
    {
        $subject = '';
        $message = '';
        $templateFrom = '';

        $templateId = !empty($templateId) ? (int) $templateId : 0;

        // 1. Load Template if provided
        if ($templateId) {
            require_once DOL_DOCUMENT_ROOT.'/core/class/cemailtemplate.class.php';
            $tpl = new CEmailTemplate($this->db);

            if ($tpl->fetch($templateId) <= 0) {
                throw new RestException(404, "Template ID {$templateId} not found.");
            }

            $subject = $tpl->subject ?? '';
            $message = $tpl->content ?? '';
            $templateFrom = $tpl->email_from ?? '';
        } else {
            // No template: Both must be present (not null)
            if ($inlineSubject === null || $inlineMessage === null) {
                throw new RestException(400, "Missing required fields 'subject' or 'message' when no template is provided.");
            }
        }

        // 2. Apply Overrides & Trim
        // Only process if NOT null (meaning key was present in request)
        if ($inlineSubject !== null) {
            $subject = trim($inlineSubject);
        }

        if ($inlineMessage !== null) {
            $message = trim($inlineMessage);
        }

        // 3. Final Validation
        if (empty($subject)) {
            throw new RestException(400, "Final subject is empty.");
        }
        if (empty($message)) {
            throw new RestException(400, "Final message is empty.");
        }

        return ['subject' => $subject, 'message' => $message, 'template_email_from' => $templateFrom];
    }

    /**
     * Resolve a single sender address specification based on type and configuration.
     *
     * This method handles the logic for resolving 'user', 'company', 'profile'
     *
     * @param array|null   $spec        Configuration array (e.g., ['type' => 'profile', 'id' => 123])
     * @param string       $defaultType Default type to use if $spec is empty or missing 'type' key
     * @return array                    Array containing 'address' (formatted), 'email' (raw), and 'signature'
     * @throws RestException(400) If validation fails:
     *                             - Missing or invalid 'type'
     *                             - Missing 'id' when type='profile'
     *                             - Empty resolved email address
     * @throws RestException(404) If the requested Sender Profile ID does not exist
     * @throws RestException(500) If no authenticated user is available (internal state error)
     */
    private function _resolveSingleAddressSpec($spec, $defaultType = 'user')
    {
        global $user, $langs;

        // Determine type directly, defaulting if missing
        // isset() safely handles null $spec without triggering warnings
        $type = isset($spec['type']) ? $spec['type'] : $defaultType;

        $name = '';
        $email = '';
        $signature = '';

        switch ($type) {
            case 'user':
                $fullName = $user->getFullName($langs);
                // Ensure fullName is a string before processing
                $name = dol_string_nospecial(is_string($fullName) ? $fullName : '', ' ', [',']);
                $email = $user->email;
                break;

            case 'company':
                $name = dol_string_nospecial(getDolGlobalString('MAIN_INFO_SOCIETE_NOM'), ' ', [',']);
                $email = getDolGlobalString('MAIN_INFO_SOCIETE_MAIL');
                break;

            case 'profile':
                // Safe check: handles null $spec and missing 'id' key
                if (empty($spec) || empty($spec['id'])) {
                    throw new RestException(400, "Profile ID required when type='profile'.");
                }

                require_once DOL_DOCUMENT_ROOT.'/core/class/emailsenderprofile.class.php';
                $profile = new EmailSenderProfile($this->db);
                $result = $profile->fetch($spec['id']);

                if ($result <= 0) {
                    $errorMsg = ($result == 0) ? "Sender profile ID {$spec['id']} not found." : $profile->error;
                    throw new RestException(404, $errorMsg);
                }

                $name = dol_string_nospecial($profile->label, ' ', [',']);
                $email = $profile->email;
                $signature = $profile->signature ?? '';
                break;

            default:
                throw new RestException(400, "Unknown sender type: '{$type}'. Valid types: user, company, profile.");
        }

        // Final validation that an email was actually resolved
        if (empty($email)) {
            throw new RestException(400, "Could not resolve email for type '{$type}'.");
        }

        return [
            'address' => ($name ? $name . ' ' : '') . '<' . $email . '>',
            'email' => $email,
            'signature' => $signature
        ];
    }

    /**
     * Resolve sender addresses (From, Reply-To, Errors-To) based on configurations.
     *
     * Implements priority chain: Explicit Request -> Template -> Default User.
     *
     * @param array|null   $fromSpec         'from' configuration from request
     * @param array|null   $replyToSpec      'reply_to' configuration from request
     * @param array|null   $errorsToSpec     'errors_to' configuration from request
     * @param string       $templateFrom     Email address string from template (fallback)
     * @return array                         ['from' => ['address', 'email', 'signature'], 'reply_to' => 'string', 'errors_to' => 'string']
     */
    private function _resolveSender($fromSpec, $replyToSpec, $errorsToSpec, $templateFrom)
    {
        // 1. Resolve FROM
        if (!empty($fromSpec)) {
            $fromData = $this->_resolveSingleAddressSpec($fromSpec, 'user');
        } elseif (!empty($templateFrom)) {
            if (preg_match('/^(.*?)<(.+)>/', $templateFrom, $matches)) {
                $fromData = ['address' => $templateFrom, 'email' => trim($matches[2]), 'signature' => ''];
            } else {
                $fromData = ['address' => $templateFrom, 'email' => $templateFrom, 'signature' => ''];
            }
        } else {
            $fromData = $this->_resolveSingleAddressSpec(null, 'user');
        }

        // 2. Resolve Reply-To
        $replyToAddress = $fromData['address'];
        if (!empty($replyToSpec)) {
            $replyData = $this->_resolveSingleAddressSpec($replyToSpec, 'user');
            $replyToAddress = $replyData['address'];
        }

        // 3. Resolve Errors-To
        $errorsToEmail = $fromData['email'];
        if (!empty($errorsToSpec)) {
            $errorData = $this->_resolveSingleAddressSpec($errorsToSpec, 'user');
            $errorsToEmail = $errorData['email'];
        }

        return [
            'from' => $fromData,
            'reply_to' => $replyToAddress,
            'errors_to' => $errorsToEmail
        ];
    }

    /**
     * Resolve recipients configuration into lists of email address strings.
     *
     * Handles multiple recipient types: thirdparty, contact, contact_type, email.
     * For 'contact_type', searches contacts attached to Source Object first,
     * then falls back to linked Thirdparty if no matches found.
     *
     * @param array       $recipientsConfig Array with 'to', 'cc', 'bcc' keys containing spec objects
     * @param Societe|null $soc             Linked thirdparty from source object (optional context)
     * @param CommonObject|null $sourceObj  The main source object (order, invoice, etc.) for priority search
     * @return array                        Associative array with 'to', 'cc', 'bcc' keys containing formatted email strings
     * @throws RestException(400) If validation fails (missing fields, unknown type, no valid 'to' recipients)
     * @throws RestException(404) If a specific resource (Thirdparty or Contact) requested by ID does not exist
     */
    private function _resolveRecipients($recipientsConfig, $soc, $sourceObj = null)
    {
        global $langs;

        $list = ['to' => [], 'cc' => [], 'bcc' => []];

        $elementTypeMap = [
            'commande' => 'order',
            'facture' => 'invoice',
            'propal' => 'propal',
            'order_supplier' => 'order_supplier', // Verify if needed
            'invoice_supplier' => 'invoice_supplier', // Verify if needed
            'supplier_proposal' => 'supplier_proposal',
        ];
        $searchElementType = null;
        if ($sourceObj && !empty($sourceObj->id)) {
            $searchElementType = $elementTypeMap[$sourceObj->element] ?? $sourceObj->element;
        }

        foreach (['to', 'cc', 'bcc'] as $field) {
            $specs = $recipientsConfig[$field] ?? [];
            if (!is_array($specs)) continue;

            foreach ($specs as $spec) {
                if (empty($spec['type'])) {
                    throw new RestException(400, "Recipient entry missing required 'type'. Valid types: thirdparty, contact, contact_type.");
                }
                $type = $spec['type'];

                if ($type === 'thirdparty') {
                    $requestedSocId = !empty($spec['id']) ? (int)$spec['id'] : null;

                    // Determine the "Allowed" Thirdparty based on Source Object
                    $allowedSoc = null;
                    if ($sourceObj && method_exists($sourceObj, 'fetch_thirdparty')) {
                        $sourceObj->fetch_thirdparty();
                        if (is_object($sourceObj->thirdparty) && $sourceObj->thirdparty->id > 0) {
                            $allowedSoc = $sourceObj->thirdparty;
                        }
                    }

                    // If a Source Object exists, FORCE the recipient to be its linked thirdparty
                    if ($allowedSoc) {
                        // If user provided an ID, verify it matches the allowed one
                        if ($requestedSocId && $requestedSocId != $allowedSoc->id) {
                            throw new RestException(403, "Access Denied: You can only send emails to the thirdparty linked to the source object (ID {$allowedSoc->id}). Provided ID {$requestedSocId} does not match.");
                        }
                        // Use the allowed thirdparty (whether requested or implicit)
                        $targetSoc = $allowedSoc;
                    } else {
                        // No source object: Must provide a valid ID
                        if (!$requestedSocId) {
                            throw new RestException(400, "Thirdparty ID required when no source_object provided.");
                        }
                        $tmpSoc = new Societe($this->db);
                        if ($tmpSoc->fetch($requestedSocId) <= 0) {
                            throw new RestException(404, "Thirdparty ID {$requestedSocId} not found.");
                        }
                        $targetSoc = $tmpSoc;
                    }

                    if (!$targetSoc || !$targetSoc->email) {
                        throw new RestException(400, "Thirdparty has no email configured.");
                    }
                    $name = dol_string_nospecial($targetSoc->name, ' ', [',']);
                    $list[$field][] = ($name ? $name . ' ' : '') . '<' . $targetSoc->email . '>';

                } elseif ($type === 'contact') {
                    if (empty($spec['id'])) {
                        throw new RestException(400, "Contact ID required.");
                    }
                    $contact = new Contact($this->db);
                    if ($contact->fetch($spec['id']) <= 0) {
                        throw new RestException(404, "Contact ID {$spec['id']} not found.");
                    }
                    if ($sourceObj && method_exists($sourceObj, 'fetch_thirdparty')) {
                        $derivedSoc = null;
                        $sourceObj->fetch_thirdparty();
                        if (is_object($sourceObj->thirdparty)) {
                            $derivedSoc = $sourceObj->thirdparty;
                        }
                        if ($derivedSoc && $contact->fk_soc != $derivedSoc->id) {
                            throw new RestException(403, "Access Denied: Contact #{$spec['id']} does not belong to the company linked to the source object (#{$sourceObj->id}).");
                        }
                    }
                    if (!$contact->email) {
                        throw new RestException(400, "Contact #{$spec['id']} has no email.");
                    }
                    $fullName = dol_string_nospecial(($contact->firstname ?: '').' '.($contact->lastname ?: ''), ' ', [',']);
                    $list[$field][] = ($fullName ? $fullName . ' ' : '') . '<' . $contact->email . '>';

                } elseif ($type === 'contact_type') {
                    if (empty($spec['code'])) {
                        throw new RestException(400, "Contact type code required.");
                    }

                    // PRIORITY 1: Search contacts attached directly to the Source Object
                    $foundContacts = [];

                    if ($sourceObj && !empty($sourceObj->id)) {
                        $sql = 'SELECT c.firstname, c.lastname, c.email';
                        $sql .= ' FROM '.MAIN_DB_PREFIX.'socpeople as c';
                        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'element_contact as ec ON c.rowid = ec.fk_socpeople';
                        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'c_type_contact as ct ON ec.fk_c_type_contact = ct.rowid';
                        $sql .= ' WHERE ec.element_id = '.((int)$sourceObj->id);
                        $sql .= " AND ec.element_type = '".$this->db->escape($searchElementType)."'";
                        $sql .= " AND ct.code = '".$this->db->escape($spec['code'])."'";

                        $resql = $this->db->query($sql);
                        if ($resql) {
                            while ($obj = $this->db->fetch_object($resql)) {
                                if (!empty($obj->email)) {
                                    $foundContacts[] = $obj;
                                }
                            }
                            $this->db->free($resql);
                        } else {
                            dol_syslog("SQL Error retrieving contacts on source object: " . $this->db->lasterror(), LOG_WARNING);
                        }
                    }

                    // PRIORITY 2: Fallback to Thirdparty contacts if not found on object
                    if (empty($foundContacts)) {
                        $targetSoc = $soc;

                        // SECURITY CHECK: If both source_object and thirdparty_id are provided, they must match
                        if (!empty($spec['thirdparty_id'])) {
                            // User explicitly specified a thirdparty
                            // Verify against derived thirdparty if source_object exists
                            if ($sourceObj && method_exists($sourceObj, 'fetch_thirdparty')) {
                                $derivedSoc = null;
                                $sourceObj->fetch_thirdparty();
                                if (is_object($sourceObj->thirdparty) && $sourceObj->thirdparty->id > 0) {
                                    $derivedSoc = $sourceObj->thirdparty;
                                }

                                if ($derivedSoc && $derivedSoc->id != $spec['thirdparty_id']) {
                                    // MISMATCH: Abort!
                                    throw new RestException(400, "Specified thirdparty_id ({$spec['thirdparty_id']}) does not match the source object's linked thirdparty ({$derivedSoc->id}).");
                                }
                            }

                            // Load the explicitly requested thirdparty
                            $tmpSoc = new Societe($this->db);
                            if ($tmpSoc->fetch($spec['thirdparty_id']) <= 0) {
                                throw new RestException(404, "Thirdparty ID {$spec['thirdparty_id']} not found.");
                            }
                            $targetSoc = $tmpSoc;
                        } elseif ($soc) {
                            // No explicit thirdparty_id, use the derived one from source_object
                            $targetSoc = $soc;
                        }

                        if (!$targetSoc) {
                            if (empty($spec['thirdparty_id'])) {
                                throw new RestException(400, "No contacts found on the source object. Thirdparty ID required for contact_type search.");
                            }

                            $tmpSoc = new Societe($this->db);
                            if ($tmpSoc->fetch($spec['thirdparty_id']) <= 0) {
                                throw new RestException(404, "Thirdparty ID {$spec['thirdparty_id']} not found.");
                            }
                            $targetSoc = $tmpSoc;
                        }

                        $sql = 'SELECT c.firstname, c.lastname, c.email';
                        $sql .= ' FROM '.MAIN_DB_PREFIX.'socpeople as c';
                        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'societe_contacts as sc ON c.rowid = sc.fk_socpeople';
                        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'c_type_contact as ct ON sc.fk_c_type_contact = ct.rowid';
                        $sql .= ' WHERE sc.fk_soc = '.((int)$targetSoc->id);
                        $sql .= " AND ct.code = '".$this->db->escape($spec['code'])."'";

                        $resql = $this->db->query($sql);
                        if ($resql) {
                            while ($obj = $this->db->fetch_object($resql)) {
                                if (!empty($obj->email)) {
                                    $foundContacts[] = $obj;
                                }
                            }
                            $this->db->free($resql);
                        } else {
                            dol_syslog("SQL Error retrieving contacts on thirdparty: " . $this->db->lasterror(), LOG_WARNING);
                        }
                    }

                    // Process found contacts
                    if (empty($foundContacts)) {
                        $logTarget = !empty($sourceObj->id) ? "Source object #{$sourceObj->id}" : "No source object";
                        dol_syslog("No contacts found with type '{$spec['code']}' for {$logTarget}", LOG_DEBUG);
                    } else {
                        foreach ($foundContacts as $obj) {
                            $name = dol_string_nospecial(($obj->firstname ?: '').' '.($obj->lastname ?: ''), ' ', [',']);
                            $list[$field][] = ($name ? $name . ' ' : '') . '<' . $obj->email . '>';
                        }
                    }

                } else {
                    throw new RestException(400, "Unknown recipient type: {$type}");
                }
            }
        }

        if (empty($list['to'])) {
            throw new RestException(400, "No valid 'to' recipients resolved. At least one must be provided.");
        }

        return $list;
    }

	/**
	 * Prepare document attachments (PDF generation).
	 *
	 * @param CommonObject|null $object		  Source object
	 * @param bool			  $shouldRegenerate Whether to force regeneration of the PDF
	 * @return array							 Array of file paths to attach, or empty
	 */
	private function _prepareAttachments($object, $shouldRegenerate)
	{
		global $user, $langs;

		if (!$object) return [];

        $supportedElements = ['commande', 'facture', 'propal', 'order_supplier', 'invoice_supplier', 'supplier_proposal'];
		if (!in_array($object->element, $supportedElements)) return [];

		// 1. CHECK PERMISSION
		// Check if user has 'creer' right for this element (required for regeneration and safe for access)
		if (empty($user->admin)) {
			if (isset(self::ELEMENT_RIGHTS_MAP[$object->element])) {
				$perm = self::ELEMENT_RIGHTS_MAP[$object->element];
				$hasRight = false;
				if (!empty($perm['sub'])) {
					if (isset($user->rights->{$perm['module']}->{$perm['sub']}->creer)) {
						$hasRight = ($user->rights->{$perm['module']}->{$perm['sub']}->creer > 0);
					}
				} else {
					if (isset($user->rights->{$perm['module']}->creer)) {
						$hasRight = ($user->rights->{$perm['module']}->creer > 0);
					}
				}
				if ($shouldRegenerate && !$hasRight) {
					throw new RestException(403, "Access Denied: You requested document regeneration but lack 'creer' (create/write) permission for {$object->element}.");
				}
			}
		}

		// 2. RE-GENERATE IF REQUESTED
		if ($shouldRegenerate) {
			$result = $object->generateDocument('', $langs);
			if ($result <= 0) {
				dol_syslog("Failed to generate PDF: " . $object->error, LOG_ERR);
				throw new RestException(500, "Document generation failed: " . $object->error);
			}
		}

		// 3. FETCH THE DOCUMENT FROM ECM INDEX
		require_once DOL_DOCUMENT_ROOT.'/ecm/class/ecmfiles.class.php';
		$ecmfile = new EcmFiles($this->db);

		// Fetch using object type and ID
		$resultFetch = $ecmfile->fetch(0, '', '', '', '', $object->element, $object->id);

		if ($resultFetch > 0 && !empty($ecmfile->filepath) && !empty($ecmfile->filename)) {
			// Construct path
			$outputDir = $this->_getOutputDirectory($object);
			if ($outputDir) {
				$modulePart = ($object->element === 'propal' ? 'propale' : $object->element);
				$relPath = $ecmfile->filepath;

				// Avoid double folder nesting
				if (stripos($relPath, $modulePart . '/') === 0) {
					$relPath = substr($relPath, strlen($modulePart) + 1);
				}
				$candidate = rtrim($outputDir, '/') . '/' . $relPath . '/' . $ecmfile->filename;
				if (file_exists($candidate)) {
					return [$candidate];
				}

				// Fallback to fullpath_orig
				if (!empty($ecmfile->fullpath_orig) && file_exists($ecmfile->fullpath_orig)) {
					return [$ecmfile->fullpath_orig];
				}
			}
		}

		return [];
	}

	/**
	 * Get the output directory path for a given Dolibarr object element.
	 * Handles both simple config variables (e.g., $conf->commande)
	 * and nested ones (e.g., $conf->compta->facture).
	 *
	 * @param CommonObject $object Dolibarr object with an `element` property (e.g., 'commande', 'facture')
	 * @return string|null		 Full path to the object's output directory, or null if not found or module disabled
	 */
	private function _getOutputDirectory($object)
	{
		global $conf;

		// 1. Handle Known Special Cases First (these need different config keys than element names)

		// Proposals: element='propal', config key='propale'
		if ($object->element === 'propal') {
			if (isset($conf->propale->dir_output)) {
				return $conf->propale->dir_output;
			}
			if (isset($conf->propal->dir_output)) {
				return $conf->propal->dir_output;
			}
			return null;
		}

		// Invoices: element='facture', config under 'compta'
		if ($object->element === 'facture') {
			if (isset($conf->compta->facture->dir_output)) {
				return $conf->compta->facture->dir_output;
			}
			return null;
		}

		// Supplier Orders: element='order_supplier', config under 'fournisseur/commande'
		if ($object->element === 'order_supplier') {
			if (isset($conf->fournisseur->commande->dir_output)) {
				return $conf->fournisseur->commande->dir_output;
			}
			return null;
		}

		// Supplier Invoices: element='invoice_supplier', config under 'fournisseur/facture'
		if ($object->element === 'invoice_supplier') {
			if (isset($conf->fournisseur->facture->dir_output)) {
				return $conf->fournisseur->facture->dir_output;
			}
			return null;
		}

		// General Case (for elements where element name = config key)
		if (isset($conf->{$object->element}->dir_output)) {
			return $conf->{$object->element}->dir_output;
		}

		dol_syslog("No output directory configured for object element: {$object->element}", LOG_DEBUG);
		return null;
	}
	/**
	 * Apply Dolibarr substitutions to subject and message text.
	 *
	 * @param string			$text		Text to apply substitutions on (subject or message)
	 * @param CommonObject|null $object	  Source object (can be null for standalone emails)
	 * @param array			 $senderInfo  Array containing sender information including signature
	 * @return string						Text with substitutions applied
	 */
	private function _applySubstitutions($text, $object, $senderInfo)
	{
		global $langs;

		$subs = getCommonSubstitutionArray($langs, 0, null, $object);

		if ($object) {
			complete_substitutions_array($subs, $langs, $object, ['mode' => 'api']);
		}

		// Inject sender signature if available
		$subs['__SENDEREMAIL_SIGNATURE__'] = $senderInfo['from']['signature'] ?? '';

		return make_substitutions($text, $subs);
	}

	/**
	 * Construct and send the email using CMailFile.
	 *
	 * @param string $subject	   Final subject line (after substitutions)
	 * @param string $message	   Final message body (HTML after substitutions)
	 * @param array  $sender		Array containing 'from' (address), 'reply_to', and 'errors_to'
	 * @param array  $recipients	Array with 'to', 'cc', 'bcc' lists of email strings
	 * @param array  $attachments   List of file paths to attach
	 * @param bool   $receipt	   Request delivery receipt?
	 * @return array				Returns ['trackid' => string, 'msgid' => string] on success
	 * @throws RestException 500	SMTP failure or sendfile error
	 */
	private function _sendEmail($subject, $message, $sender, $recipients, $attachments, $receipt)
	{
		dol_syslog(__METHOD__.'::attachments='.$attachments, LOG_DEBUG);
		global $conf, $user;
        $trackid = 'ema' . substr(hash('sha256', random_bytes(32)), 0, self::TRACKID_LENGTH);
		$tmpDir = $conf->user->dir_output.'/'.$user->id.'/temp';
		if (!is_dir($tmpDir)) dol_mkdir($tmpDir);

		$mimeFilenameList = [];
		foreach ($attachments as $path) {
			if (is_file($path)) {
				$mimeFilenameList[] = basename($path); // e.g., "(PROV2688).pdf"
			}
		}

		$mail = new CMailFile(
			$subject,
			implode(',', $recipients['to']),
			$sender['from']['address'],
			$message,
			$attachments, [], $mimeFilenameList,
			!empty($recipients['cc']) ? implode(',', $recipients['cc']) : '',
			!empty($recipients['bcc']) ? implode(',', $recipients['bcc']) : '',
			$receipt ? 1 : 0,
			-1,
			$sender['errors_to'], // CORRECTED: Moved here (Parameter 12)
			'',				   // $css
			$trackid,
			'',				   // $moreinheader
			'standard',		   // $sendcontext
			$sender['reply_to'],  // $replyto
			$tmpDir,			  // $upload_dir_tmp
			'',				   // $in_reply_to (Leave empty unless replying)
			''					// $references
		);

		if ($mail->error || !empty($mail->errors)) {
			throw new RestException(500, "SMTP Error: ".($mail->error ?: implode(', ', $mail->errors)));
		}

		if (!$mail->sendfile()) {
			// Attempt to get any error message set during the send attempt
			$errorMsg = $mail->error ?: 'Unknown send failure';
			throw new RestException(500, "Failed to send email: " . $errorMsg);
		}

        // --- CLEANUP: Remove temporary files created by CMailFile ---
        // This prevents accumulation of orphaned temp files in user's output directory
        if (is_dir($tmpDir)) {
            // Find all files (not directories) in the temp folder
            $files = glob(rtrim($tmpDir, '/') . '/*');

            if ($files && is_array($files)) {
                foreach ($files as $file) {
                    // Only delete if it's a regular file
                    if (is_file($file)) {
                        // @ suppresses warnings if file is locked or permission denied
                        if (!@unlink($file)) {
                            // Log the specific failure instead of swallowing it
                            dol_syslog("Failed to delete temporary file: {$file}", LOG_WARNING);
                        }
                    }
                }
            }
        }
        // --- End Cleanup ---

		return ['trackid' => $trackid, 'msgid' => $mail->msgid];
	}

	/**
	 * Execute a trigger after successful email sending.
	 *
	 * @param string	   $name	Trigger name (e.g., ORDER_SENTBYMAIL, BILL_SENTBYMAIL)
	 * @param CommonObject $object  The Dolibarr object to pass to the trigger
	 * @return void
	 * @throws RestException 500	Trigger execution failed
	 */
	private function _executeTrigger($name, $object)
	{
		global $user;
		if (!$object) return;

		$res = $object->call_trigger($name, $user);
		if ($res < 0) {
			throw new RestException(500, "Trigger execution failed: " . $object->error);
		}
	}
}
