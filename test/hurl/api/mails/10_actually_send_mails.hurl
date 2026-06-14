# ============================================
# SECURITY & VALIDATION TESTS (Descriptive Subjects)
# ============================================
# Uses: Proposal 2688, Thirdparty 525, Contact 2098
# Known State: Proposal 2688 links to Thirdparty 524 (NOT 525)
# Known Data: Soc 524 HAS BILLING contacts (Test 7 succeeds)

# 1. TEST: Direct Thirdparty Mismatch (Prop 2688 -> Soc 525)
POST http://{{hostnport}}/api/index.php/mails/send
{
  "source_object": {
    "type": "propal",
    "id": 2688
  },
  "from": {
    "type": "user"
  },
  "recipients": {
    "to": [
      {"type": "thirdparty", "id": 525}
    ]
  },
  "subject": "Test 101: Attempting to send Proposal 2688 directly to unrelated Thirdparty 525",
  "message": "<p>This test attempts to bypass security by sending Proposal 2688 (linked to Soc 524) directly to Thirdparty 525.</p>"
}
HTTP 403
[Asserts]
jsonpath "$.error.code" == 403
jsonpath "$.error.message" contains "does not match"

# 2. TEST: Unlinked Contact Access Check
POST http://{{hostnport}}/api/index.php/mails/send
{
  "source_object": {
    "type": "propal",
    "id": 2688
  },
  "from": {
    "type": "user"
  },
  "recipients": {
    "to": [
      {"type": "contact", "id": 2098}
    ]
  },
  "subject": "Test 102: Attempting to send Proposal 2688 to Contact 2098 (not linked to Soc 524)",
  "message": "<p>This test verifies that Contact 2098 cannot receive emails for Proposal 2688 because it does not belong to the linked thirdparty.</p>"
}
HTTP 403
[Asserts]
jsonpath "$.error.message" contains "Access Denied"

# 3. TEST: Contact Type with Wrong Thirdparty ID
POST http://{{hostnport}}/api/index.php/mails/send
{
  "source_object": {
    "type": "propal",
    "id": 2688
  },
  "from": {
    "type": "user"
  },
  "recipients": {
    "to": [
      {"type": "contact_type", "code": "BILLING", "thirdparty_id": 525}
    ]
  },
  "subject": "Test 103: Searching for BILLING contacts on Thirdparty 525 while using Proposal 2688 context",
  "message": "<p>This test checks if the API correctly rejects a request to find BILLING contacts on Thirdparty 525 when the proposal is linked to a different thirdparty.</p>"
}
HTTP 400
[Asserts]
jsonpath "$.error.message" contains "does not match"

# 4. TEST: PDF Attachment to Mismatched Thirdparty
POST http://{{hostnport}}/api/index.php/mails/send
{
  "source_object": {
    "type": "propal",
    "id": 2688
  },
  "from": {
    "type": "user"
  },
  "recipients": {
    "to": [
      {"type": "thirdparty", "id": 525}
    ]
  },
  "subject": "Test 104: Requesting PDF regeneration for Proposal 2688 to be sent to Thirdparty 525",
  "message": "<p>This test attempts to regenerate and send the PDF of Proposal 2688 to an unauthorized recipient (Thirdparty 525).</p>",
  "options": {
    "regenerate_documents": true
  }
}
HTTP 403
[Asserts]
jsonpath "$.error.code" == 403

# 5. TEST: CC with Unlinked Contact
POST http://{{hostnport}}/api/index.php/mails/send
{
  "source_object": {
    "type": "propal",
    "id": 2688
  },
  "from": {
    "type": "user"
  },
  "recipients": {
    "to": [
      {"type": "thirdparty", "id": 525}
    ],
    "cc": [
      {"type": "contact", "id": 2098}
    ]
  },
  "subject": "Test 105: Sending to Thirdparty 525 with CC to unlinked Contact 2098",
  "message": "<p>This test verifies that the API blocks a valid 'to' recipient if the 'cc' contact (2098) is not linked to the proposal's thirdparty.</p>"
}
HTTP 403
[Asserts]
jsonpath "$.error.message" contains "Access Denied"

# 6. TEST: SUCCESS - Sending to Correct Linked Thirdparty
POST http://{{hostnport}}/api/index.php/mails/send
{
  "source_object": {
    "type": "propal",
    "id": 2688
  },
  "from": {
    "type": "user"
  },
  "recipients": {
    "to": [
      {"type": "thirdparty", "id": 524}
    ]
  },
  "subject": "Test 106: Successfully sending Proposal 2688 to its correct linked Thirdparty 524",
  "message": "<p>This is a success case verifying that emails can be sent normally when the recipient matches the proposal's linked thirdparty.</p>"
}
HTTP 200
[Asserts]
jsonpath "$.success.code" == 200
jsonpath "$.success.message" contains "sent successfully"

# 7. TEST: Billing Contacts Found on Correct Thirdparty (UPDATED to Expect 200)
POST http://{{hostnport}}/api/index.php/mails/send
{
  "source_object": {
    "type": "propal",
    "id": 2688
  },
  "from": {
    "type": "user"
  },
  "recipients": {
    "to": [
      {"type": "contact_type", "code": "BILLING", "thirdparty_id": 524}
    ]
  },
  "subject": "Test 107: Successfully finding BILLING contacts on the correct Thirdparty 524",
  "message": "<p>This test confirms that BILLING contacts exist on Thirdparty 524 and the email sends successfully to them.</p>"
}
HTTP 200
[Asserts]
jsonpath "$.success.code" == 200
jsonpath "$.success.message" contains "sent successfully"


# ============================================
# TEMPLATE VALIDATION TESTS - Batch 110+
# ============================================
# Uses: Proposal 2688, Thirdparty 524 (Correct link)
# Templates: 23 (Proposal), 19 (Non-Proposal), 99999 (Invalid)

# Test 110: SUCCESS - Send with Valid Proposal Template (ID 23)
# Expected: 200 OK (Email sent with correct template)
POST http://{{hostnport}}/api/index.php/mails/send
Content-Type: application/json
{
  "source_object": {
    "type": "propal",
    "id": 2688
  },
  "from": {
    "type": "user"
  },
  "template_id": 23,
  "recipients": {
    "to": [
      {"type": "thirdparty", "id": 524}
    ]
  },
  "subject": "Test 110: Valid Proposal Template 23",
  "message": "<p>Test 110: Sending email with correct proposal template.</p>",
  "options": {
    "regenerate_documents": true
  }
}
HTTP 200
[Asserts]
jsonpath "$.success.code" == 200
jsonpath "$.success.message" contains "sent successfully"

# Test 111: SECURITY LOGIC - Send with Mismatched Template Type (Template ID 19)
# Assumes Template 19 is NOT for proposals (e.g., an Order template).
# Expected: 400 Bad Request (Incompatible types) OR 404 if strict filtering removes it.
POST http://{{hostnport}}/api/index.php/mails/send
Content-Type: application/json
{
  "source_object": {
    "type": "propal",
    "id": 2688
  },
  "from": {
    "type": "user"
  },
  "template_id": 19,
  "recipients": {
    "to": [
      {"type": "thirdparty", "id": 524}
    ]
  },
  "subject": "Test 111: Mismatched Template 19 for Proposal 2688",
  "message": "<p>Test 111: Verifying rejection of non-proposal template type.</p>"
}
HTTP 400
[Asserts]
jsonpath "$.error.code" == 400
# Check for common rejection messages regarding type mismatch or invalid template for object
jsonpath "$.error.message" contains "not found"
jsonpath "$.error.message" contains "mismatch"
jsonpath "$.error.message" contains "type"

# Test 112: ERROR HANDLING - Send with Non-Existent Template (ID 99999)
# Expected: 404 Not Found
POST http://{{hostnport}}/api/index.php/mails/send
Content-Type: application/json
{
  "source_object": {
    "type": "propal",
    "id": 2688
  },
  "from": {
    "type": "user"
  },
  "template_id": 99999,
  "recipients": {
    "to": [
      {"type": "thirdparty", "id": 524}
    ]
  },
  "subject": "Test 112: Non-Existent Template 99999",
  "message": "<p>Test 112: Verifying rejection of missing template ID.</p>"
}
HTTP 404
[Asserts]
jsonpath "$.error.code" == 404
jsonpath "$.error.message" contains "not found"
jsonpath "$.error.message" contains "99999"
