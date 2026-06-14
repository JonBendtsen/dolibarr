# ============================================
# PERMISSION TESTS: DOCUMENT REGENERATION
# ============================================

POST http://{{hostnport}}/api/index.php/mails/send
DOLAPIKEY: 1vS57H1w91lUbWyw5N3tFTUT2tkPFj0q
Content-Type: application/json
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
  "subject": "Test 224: Regeneration Blocked Due to Missing Permissions",
  "message": "<p>Test 224: Verifying that users without 'creer' rights cannot regenerate PDFs.</p>",
  "options": {
    "regenerate_documents": true
  }
}
HTTP 403
[Asserts]
jsonpath "$.error.code" == 403
jsonpath "$.error.message" contains "Access Denied"
jsonpath "$.error.message" contains "creer"
