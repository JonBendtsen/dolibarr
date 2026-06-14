# ============================================
# Dolibarr Mails API Test Suite - Validation & Security
# Tests for /api/index.php/mails/ endpoints
# ============================================

# GET /schema - Should return valid JSON schema
GET http://{{hostnport}}/api/index.php/mails/schema
HTTP 200
[Asserts]
jsonpath "$.type" == "object"
jsonpath "$.description" exists
jsonpath "$.required" contains "recipients"
jsonpath "$.properties.recipients" exists
jsonpath "$.properties.source_object" exists
jsonpath "$.properties.from" exists
jsonpath "$.properties.template_id" exists

# ============================================
# MISSING REQUIRED FIELDS TESTS
# ============================================

# Test 1: Empty payload - No recipients, no subject, no message
POST http://{{hostnport}}/api/index.php/mails/send
{}
HTTP 400
[Asserts]
jsonpath "$.error.code" exists

# Test 2: Missing recipients entirely but with subject/message provided
POST http://{{hostnport}}/api/index.php/mails/send
{
  "subject": "Test 2: Missing Recipients Check",
  "message": "Test 2: This request has content but no recipients defined."
}
HTTP 400
[Asserts]
jsonpath "$.error.message" contains "No valid 'to' recipients"
jsonpath "$.error.message" contains "At least one"

# Test 3: Recipients field exists but is an empty object
POST http://{{hostnport}}/api/index.php/mails/send
{
  "recipients": {}
}
HTTP 400
[Asserts]
jsonpath "$.error.code" exists

# Test 4: Recipients.to is an empty array, WITH subject and message
POST http://{{hostnport}}/api/index.php/mails/send
{
  "recipients": {
    "to": []
  },
  "subject": "Test 4: Empty To Array Validation",
  "message": "Test 4: The 'to' array is explicitly empty."
}
HTTP 400
[Asserts]
jsonpath "$.error.message" contains "No valid 'to' recipients"

# Test 5: Recipient entry missing required 'type' field
POST http://{{hostnport}}/api/index.php/mails/send
{
  "recipients": {
    "to": [
      {"id": 123}
    ]
  },
  "subject": "Test 5: Missing Recipient Type Field",
  "message": "Test 5: This recipient has an ID but no type specified."
}
HTTP 400
[Asserts]
jsonpath "$.error.message" contains "missing required 'type'"

# ============================================
# INVALID RECIPIENT TYPES
# ============================================

# Test 6: Invalid recipient type value (e.g., 'unknown')
POST http://{{hostnport}}/api/index.php/mails/send
{
  "recipients": {
    "to": [
      {"type": "invalid_type_value", "id": 123}
    ]
  },
  "subject": "Test 6: Unknown Recipient Type Value",
  "message": "Test 6: Testing rejection of an invalid recipient type string."
}
HTTP 400
[Asserts]
jsonpath "$.error.message" contains "Unknown recipient type"

# ============================================
# CONTACT TYPE VALIDATION TESTS
# ============================================

# Test 7: Contact type without ID (for type='contact')
POST http://{{hostnport}}/api/index.php/mails/send
{
  "recipients": {
    "to": [
      {"type": "contact"}
    ]
  },
  "subject": "Test 7: Contact Recipient Missing ID",
  "message": "Test 7: A contact type requires an explicit ID."
}
HTTP 400
[Asserts]
jsonpath "$.error.message" contains "Contact ID required"

# Test 8: Contact type without code (for type='contact_type')
POST http://{{hostnport}}/api/index.php/mails/send
{
  "recipients": {
    "to": [
      {"type": "contact_type"}
    ]
  },
  "subject": "Test 8: Contact Type Recipient Missing Code",
  "message": "Test 8: A contact_type requires a specific code like BILLING."
}
HTTP 400
[Asserts]
jsonpath "$.error.message" contains "Contact type code required"

# ============================================
# THIRD PARTY VALIDATION TESTS
# ============================================

# Test 9: Thirdparty without ID when no source_object provided
POST http://{{hostnport}}/api/index.php/mails/send
{
  "recipients": {
    "to": [
      {"type": "thirdparty"}
    ]
  },
  "subject": "Test 9: Thirdparty Recipient Missing ID",
  "message": "Test 9: Without a source object, a thirdparty ID must be provided."
}
HTTP 400
[Asserts]
jsonpath "$.error.message" contains "Thirdparty ID required"

# ============================================
# SOURCE OBJECT VALIDATION TESTS
# ============================================

# Test 10: Source object without type specified
POST http://{{hostnport}}/api/index.php/mails/send
{
  "source_object": {
    "id": 2688
  },
  "recipients": {
    "to": [
      {"type": "contact_type", "code": "SPILLING", "thirdparty_id": 525}
    ]
  },
  "subject": "Test 10: Source Object Missing Type Property",
  "message": "Test 10: The source object must specify its type (e.g., propal)."
}
HTTP 400
[Asserts]
jsonpath "$.error.code" exists

# Test 11: Source object without ID specified
POST http://{{hostnport}}/api/index.php/mails/send
{
  "source_object": {
    "type": "propal"
  },
  "recipients": {
    "to": [
      {"type": "contact_type", "code": "BILLING", "thirdparty_id": 525}
    ]
  },
  "subject": "Test 11: Source Object Missing ID Property",
  "message": "Test 11: The source object must specify a valid ID."
}
HTTP 400
[Asserts]
jsonpath "$.error.code" == 400
jsonpath "$.error.message" contains "Invalid"

# Test 12: Invalid source object type (unsupported entity)
POST http://{{hostnport}}/api/index.php/mails/send
{
  "source_object": {
    "type": "non_existent_object_type",
    "id": 2688
  },
  "recipients": {
    "to": [
      {"type": "contact_type", "code": "BILLING", "thirdparty_id": 525}
    ]
  },
  "subject": "Test 12: Unsupported Source Object Type",
  "message": "Test 12: Verifying rejection of an object type not in the map."
}
HTTP 400
[Asserts]
jsonpath "$.error.message" contains "Unsupported object type"

# Test 13: Cross-Context Security - Proposal 2688 with unrelated Thirdparty 525
POST http://{{hostnport}}/api/index.php/mails/send
{
  "source_object": {
    "type": "propal",
    "id": 2688
  },
  "recipients": {
    "to": [
      {
        "type": "contact_type",
        "code": "BILLING",
        "thirdparty_id": 525
      }
    ]
  },
  "subject": "Test 13: Security Block - Mismatched Thirdparty ID",
  "message": "<p>Test 13: Attempting to use Thirdparty 525 while Prop 2688 links to 524.</p>"
}
HTTP 400
[Asserts]
jsonpath "$.error.message" contains "does not match"

# ============================================
# CROSS-CONTEXT SECURITY TESTS
# ============================================

# Test 14: Security Check - Unlinked Contact Access
POST http://{{hostnport}}/api/index.php/mails/send
{
  "source_object": {
    "type": "propal",
    "id": 2688
  },
  "recipients": {
    "to": [
      {
        "type": "contact",
        "id": 2098
      }
    ]
  },
  "subject": "Test 14: Security Block - Unlinked Contact ID",
  "message": "<p>Test 14: Attempting to send to Contact 2098 which does not belong to Prop 2688's linked thirdparty.</p>"
}
HTTP 403
[Asserts]
jsonpath "$.error.message" contains "Access Denied"
jsonpath "$.error.message" contains "does not belong"

# ============================================
# SENDER CONFIGURATION TESTS
# ============================================

# Test 15: Invalid sender type
POST http://{{hostnport}}/api/index.php/mails/send
{
  "from": {
    "type": "unknown_sender_type"
  },
  "recipients": {
    "to": [
      {"type": "thirdparty", "id": 525}
    ]
  },
  "subject": "Test 15: Unknown Sender Type Configuration",
  "message": "Test 15: Verifying rejection of an invalid 'from' type."
}
HTTP 400
[Asserts]
jsonpath "$.error.message" contains "Unknown sender type"

# Test 16: Profile sender type without required ID
POST http://{{hostnport}}/api/index.php/mails/send
{
  "from": {
    "type": "profile"
  },
  "recipients": {
    "to": [
      {"type": "thirdparty", "id": 525}
    ]
  },
  "subject": "Test 16: Profile Sender Missing ID",
  "message": "Test 16: A profile type sender requires a valid profile ID."
}
HTTP 400
[Asserts]
jsonpath "$.error.message" contains "Profile ID required"

# Test 17: Non-existent sender profile ID
POST http://{{hostnport}}/api/index.php/mails/send
{
  "from": {
    "type": "profile",
    "id": 99999
  },
  "recipients": {
    "to": [
      {"type": "thirdparty", "id": 525}
    ]
  },
  "subject": "Test 17: Non-Existent Sender Profile ID",
  "message": "Test 17: Attempting to use a sender profile ID that does not exist."
}
HTTP 404
[Asserts]
jsonpath "$.error.message" contains "Sender profile ID"
jsonpath "$.error.message" contains "not found"

# ============================================
# TEMPLATE AND CONTENT TESTS
# ============================================

# Test 18: Invalid template ID
POST http://{{hostnport}}/api/index.php/mails/send
{
  "template_id": 99999,
  "recipients": {
    "to": [
      {"type": "thirdparty", "id": 525}
    ]
  }
}
HTTP 404
[Asserts]
jsonpath "$.error.message" contains "Template ID"
jsonpath "$.error.message" contains "not found"

# Test 19: No template, no subject provided (only message)
POST http://{{hostnport}}/api/index.php/mails/send
{
  "recipients": {
    "to": [
      {"type": "thirdparty", "id": 525}
    ]
  },
  "message": "Test 19: This message exists but the subject is missing."
}
HTTP 400
[Asserts]
jsonpath "$.error.message" contains "Missing required fields"

# Test 20: No template, no message provided (only subject)
POST http://{{hostnport}}/api/index.php/mails/send
{
  "recipients": {
    "to": [
      {"type": "thirdparty", "id": 525}
    ]
  },
  "subject": "Test 20: This subject exists but the message body is missing."
}
HTTP 400
[Asserts]
jsonpath "$.error.message" contains "Missing required fields"

# ============================================
# PERMISSION CHECK TESTS
# ============================================

# Test 21: Invalid proposal ID (ID 0)
POST http://{{hostnport}}/api/index.php/mails/send
{
  "source_object": {
    "type": "propal",
    "id": 0
  },
  "recipients": {
    "to": [
      {"type": "thirdparty", "id": 525}
    ]
  },
  "subject": "Test 21: Invalid Object ID Zero",
  "message": "Test 21: Attempting to use ID 0 which is not a valid positive integer."
}
HTTP 400
[Asserts]
jsonpath "$.error.message" contains "Invalid object ID"
jsonpath "$.error.message" contains "positive integer"

# ============================================
# RECIPIENT ARRAY FORMAT TESTS
# ============================================

# Test 22: CC field with invalid format (string instead of object array)
POST http://{{hostnport}}/api/index.php/mails/send
{
  "recipients": {
    "to": [
      {"type": "thirdparty", "id": 525}
    ],
    "cc": ["invalid_string_format_instead_of_object"]
  },
  "subject": "Test 22: Malformed CC Recipient Array",
  "message": "Test 22: The CC array contains strings instead of recipient objects."
}
HTTP 400
[Asserts]
jsonpath "$.error.code" exists

# ============================================
# RESPONSE FORMAT VERIFICATION
# ============================================

# Test 23: Verify consistent JSON error structure on empty payload
POST http://{{hostnport}}/api/index.php/mails/send
{}
HTTP 400
[Asserts]
jsonpath "$.error" != null
jsonpath "$.error.code" != null
