# Clinic-Lab Integration API Reference

This document describes all API integration endpoints exposed by the Dental Lab Management System to connect with external Clinic Portals.

---

## Authentication & Headers

All integration endpoints are secured via an API key check and require the following headers:

| Header | Type | Description |
| :--- | :--- | :--- |
| `X-API-Key` | String | The secure API key assigned to the clinic branch (e.g. `dlk_examplekey12345`). |
| `Content-Type` | String | Must be set to `application/json` for `POST` requests. |

---

## Endpoints List

### 1. Initial Config Setup
Configure the integration link between the clinic and the lab. This registers or updates the clinic basic details.

* **URL:** `POST /api/integration/config`
* **Request Body Structure:**
```json
{
  "clinicUrl": "https://smiledental.com", // (Required) Unique URL of the clinic
  "clinicName": "Smile Dental Clinic"    // (Required) Human-readable name of the clinic
}
```
* **Response (200 OK):**
```json
{
  "success": true,
  "lab": {
    "id": "lab-branch-uuid-456",
    "name": "Downtown Lab Branch",
    "code": "DTL",
    "organization": "Mega Dental Labs Inc"
  },
  "clinic": {
    "id": "clinic-uuid-in-lab-database",
    "name": "Smile Dental Clinic",
    "url": "https://smiledental.com"
  }
}
```

---

### 2. Request Work Order Setup Metadata
Fetch available prosthesis types (to populate dropdowns) and retrieve the next valid sequential folio number.

* **URL:** `GET /api/integration/work-orders/setup`
* **Request Body:** None
* **Response (200 OK):**
```json
{
  "prosthesisTypes": [
    {
      "id": "prosthesis-type-uuid-1",
      "name": "Zirconia Crown",
      "description": "High-translucency monolithic zirconia restoration"
    },
    {
      "id": "prosthesis-type-uuid-2",
      "name": "E.Max Veneer",
      "description": "Lithium disilicate glass-ceramic veneer"
    }
  ],
  "nextFolioNumber": "DTL0042"
}
```

---

### 3. Create Work Order
Create a new work order from the clinic portal. When creating the order, the system dynamically registers/resolves the doctor details under the configured clinic.

* **URL:** `POST /api/integration/work-orders`
* **Request Body Structure:**
```json
{
  "clinicUrl": "https://smiledental.com",         // (Required) Unique URL of the clinic
  "doctorName": "Dr. Jane Doe",                  // (Required) Doctor's full name
  "doctorEmail": "jane.doe@example.com",         // (Optional) Doctor's email
  "doctorPhone": "+1234567890",                  // (Optional) Doctor's phone number
  "doctorAddress": "123 Main St, Suite 4B",      // (Optional) Doctor's address
  "patient": "John Smith",                       // (Required) Patient's full name
  "prosthesisTypeId": "prosthesis-type-uuid-1",  // (Required) Prosthesis Type database UUID
  "boxNumber": "Box 105",                        // (Optional) Physical work box number
  "fileNumber": "FILE-101",                      // (Optional) Clinic file number
  "color": "A2",                                 // (Optional) Teeth color specification
  "specification": "Zirconia Crown on Tooth #14", // (Optional/Required per lab) Spec details
  "deliveryDate": "2026-08-15T00:00:00.000Z",     // (Optional) ISO 8601 delivery date
  "notes": "Urgent, please complete before Sat.",// (Optional) Specific fabrication notes
  "totalQuote": 0,                               // Sent by clinic (hidden on form; default 0)
  "initialPayment": 0,                           // Sent by clinic (hidden on form; default 0)
  "paymentReferenceNumber": "",                  // Sent by clinic (hidden on form; default "")
  "paymentReferenceNumbers": []                  // Sent by clinic (hidden on form; default [])
}
```
* **Response (201 Created):**
```json
{
  "id": "work-order-uuid-1234",
  "tenantId": "tenant-uuid-5678",
  "branchId": "lab-branch-uuid-456",
  "folioNumber": "DTL0042",
  "doctorId": "doctor-uuid-in-lab-database",
  "patient": "John Smith",
  "boxNumber": "Box 105",
  "fileNumber": "FILE-101",
  "prosthesisTypeId": "prosthesis-type-uuid-1",
  "specification": null,
  "color": "A2",
  "notes": "Urgent, please complete before Sat.",
  "totalQuote": 450,
  "initialPayment": 100,
  "qrToken": "qr-token-uuid-abcd",
  "status": "CREATED",
  "repetitionCount": 0,
  "createdById": "admin-user-uuid",
  "createdAt": "2026-07-15T13:24:22.000Z",
  "updatedAt": "2026-07-15T13:24:22.000Z",
  "processes": [
    {
      "id": "process-uuid-99",
      "workOrderId": "work-order-uuid-1234",
      "processName": "Scanning",
      "sequence": 0,
      "isVerification": false,
      "status": "NOT_STARTED"
    },
    {
      "id": "process-uuid-100",
      "workOrderId": "work-order-uuid-1234",
      "processName": "Quality Check",
      "sequence": 1,
      "isVerification": true,
      "status": "NOT_STARTED"
    }
  ]
}
```