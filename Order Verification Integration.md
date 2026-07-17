# Implementation Plan: Clinic-Lab Work Order Verification Integration

This plan details the design and implementation steps for completing the Work Order process flow from the Clinic perspective, specifically addressing the tracking of external verification start, end outcomes (SUCCESS, REPETITION, REWORK), and clinic/doctor notifications.

---

## Endpoint Structures

Below are the complete API request/response structures. All integration endpoints must include the `X-API-Key` header for authentication.

### 1. Start Verification
External clinic/doctor notifies that they have started the verification process.

* **URL**: `POST /api/integration/work-orders/start-verification`
* **Headers**:
  * `X-API-Key: <api_key>`
  * `Content-Type: application/json`
* **Request Body**:
  ```json
  {
    "clinicUrl": "https://smiledental.com",
    "doctorId": "doctor-uuid-in-lab-database",
    "workOrderId": "work-order-uuid-in-lab-database"
  }
  ```
* **Response (200 OK)**:
  ```json
  {
    "success": true,
    "message": "External verification start recorded successfully."
  }
  ```

### 2. End Verification
External clinic/doctor submits their verification decision (`SUCCESS`, `REPETITION`, or `REWORK`).

* **URL**: `POST /api/integration/work-orders/verify`
* **Headers**:
  * `X-API-Key: <api_key>`
  * `Content-Type: application/json`
* **Request Body (SUCCESS)**:
  ```json
  {
    "clinicUrl": "https://smiledental.com",
    "doctorId": "doctor-uuid-in-lab-database",
    "workOrderId": "work-order-uuid-in-lab-database",
    "outcome": "SUCCESS",
    "notes": "Crown looks perfect and fits nicely."
  }
  ```
* **Request Body (REPETITION)**:
  ```json
  {
    "clinicUrl": "https://smiledental.com",
    "doctorId": "doctor-uuid-in-lab-database",
    "workOrderId": "work-order-uuid-in-lab-database",
    "outcome": "REPETITION",
    "notes": "Needs full restart due to material failure."
  }
  ```
* **Request Body (REWORK)**:
  ```json
  {
    "clinicUrl": "https://smiledental.com",
    "doctorId": "doctor-uuid-in-lab-database",
    "workOrderId": "work-order-uuid-in-lab-database",
    "outcome": "REWORK",
    "reworkProcessNames": ["Scanning", "Design"],
    "notes": "Margins are too thick on the scan, please re-scan and re-design."
  }
  ```
* **Response (200 OK)**:
  ```json
  {
    "success": true,
    "message": "Verification outcome REWORK submitted successfully.",
    "nextStep": {
      "processName": "Scanning",
      "status": "NOT_STARTED"
    }
  }
  ```

### 3. Clinic Notification Callback (Triggered by Lab Application)
Sent automatically to the external clinic's system when the work order enters the external verification stage.

* **URL**: `POST <clinicUrl>/api/integration/notifications`
* **Headers**:
  * `X-API-Key: <lab_api_key>`
  * `Content-Type: application/json`
* **Request Body**:
  ```json
  {
    "event": "EXTERNAL_VERIFICATION_REQUESTED",
    "workOrderId": "work-order-uuid",
    "folioNumber": "DTL0042",
    "patient": "John Smith",
    "processName": "Quality Check",
    "doctor": {
      "id": "doctor-uuid",
      "name": "Dr. Jane Doe",
      "email": "jane.doe@example.com"
    }
  }
  ```
* **Response**: Expected `200 OK` or `204 No Content` from the clinic system.

---
