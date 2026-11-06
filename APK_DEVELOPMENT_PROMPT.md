# Master Development Specification: Friendly AI Solution Mobile Application (Android APK)

## 1. Application Overview
This document serves as the master technical blueprint and implementation prompt to build the **"Friendly AI Solution"** native mobile application from scratch.

* **Application Name:** Friendly AI Solution
* **Package Name / Application ID:** `com.friendlyaisolution.crm`
* **Target Platforms:** Android (Target SDK: 35, Min SDK: 24), iOS compatible
* **Primary Framework:** Flutter 3.x (Dart 3)
* **Production Backend URL:** `https://friendlyaisolution.com`

---

## 2. Core Architectural & Design Directives

1. **Zero Emojis Policy:**
   * Strict adherence to zero emojis across all screens, dialogs, form validation messages, and system alerts.
   * Exclusively utilize clean, modern vector icons (Lucide Icons or Material Symbols).

2. **Enterprise UI Standard:**
   * Theme Palette:
     - Primary Dark: Slate 900 (`#0F172A`)
     - Brand Accent: Indigo 600 (`#4F46E5`)
     - Success / Present: Emerald 600 (`#059669`)
     - Warning / Outside Geofence: Amber 600 (`#D97706`)
     - Danger / Action Required: Rose 600 (`#E11D48`)
     - Surface / Canvas: Light Neutral (`#F8FAFC`) with crisp white card surfaces (`#FFFFFF`) and 1px borders (`#E2E8F0`).
   * Typography: Clean sans-serif (Inter, Roboto, or system font) with clear hierarchy and consistent padding.

3. **Multi-Tenant Data Isolation:**
   * All API requests must operate under the authenticated user's `tenant_id` and `user_id`.
   * Cross-tenant data contamination is strictly blocked by the backend API.

4. **Location Privacy & Zero Covert Tracking Policy:**
   * Geolocation coordinates (`lat`, `lng`, `accuracy`) must **only** be gathered during explicit user actions (tapping Check-In or Check-Out).
   * No continuous background location tracking or battery-draining passive spyware.

5. **Hardware Encrypted Token Storage:**
   * Authentication tokens must be stored using `flutter_secure_storage` (backed by Android Keystore on Android and Keychain on iOS).
   * Plaintext storage in shared preferences is forbidden.

---

## 3. Recommended Dependencies (`pubspec.yaml`)

```yaml
name: friendlyaisolution
description: "Friendly AI Solution CRM & Attendance Mobile Client"
publish_to: 'none'
version: 1.0.0+1

environment:
  sdk: '>=3.0.0 <4.0.0'

dependencies:
  flutter:
    sdk: flutter
  dio: ^5.4.1                      # HTTP client with interceptor support
  flutter_secure_storage: ^9.0.0   # Hardware Keystore encrypted storage
  geolocator: ^11.0.0              # High-precision GPS sensor
  camera: ^0.10.5+9                # Biometric selfie camera controller
  image: ^4.1.7                    # Client-side image compression
  intl: ^0.19.0                    # Date and time formatting
  provider: ^6.1.2                 # State management
  lucide_icons: ^0.257.0           # Enterprise vector icon set

dev_dependencies:
  flutter_test:
    sdk: flutter
  flutter_lints: ^3.0.0
```

---

## 4. Android Manifest Configuration (`android/app/src/main/AndroidManifest.xml`)

```xml
<manifest xmlns:android="http://schemas.android.com/apk/res/android"
    package="com.friendlyaisolution.crm">

    <uses-permission android:name="android.permission.INTERNET" />
    <uses-permission android:name="android.permission.ACCESS_NETWORK_STATE" />
    <uses-permission android:name="android.permission.ACCESS_FINE_LOCATION" />
    <uses-permission android:name="android.permission.ACCESS_COARSE_LOCATION" />
    <uses-permission android:name="android.permission.CAMERA" />

    <uses-feature android:name="android.hardware.camera" android:required="true" />
    <uses-feature android:name="android.hardware.camera.front" android:required="true" />
    <uses-feature android:name="android.hardware.location.gps" android:required="true" />

    <application
        android:label="Friendly AI Solution"
        android:name="${applicationName}"
        android:icon="@mipmap/ic_launcher"
        android:usesCleartextTraffic="false">
        <activity
            android:name=".MainActivity"
            android:exported="true"
            android:launchMode="singleTop"
            android:theme="@style/LaunchTheme"
            android:configChanges="orientation|keyboardHidden|keyboard|screenSize|smallestScreenSize|locale|layoutDirection|fontScale|screenLayout|density|uiMode"
            android:hardwareAccelerated="true"
            android:windowSoftInputMode="adjustResize">
            <intent-filter>
                <action android:name="android.intent.action.MAIN"/>
                <category android:name="android.intent.category.LAUNCHER"/>
            </intent-filter>
        </activity>
    </application>
</manifest>
```

---

## 5. API Endpoints & Request/Response Contracts

### Base URL:
`https://friendlyaisolution.com`

### Authentication Header:
For all protected endpoints, attach:
`Authorization: Bearer <token>`

---

### Endpoint 1: Mobile Login
* **Method:** `POST`
* **Path:** `/api/mobile_auth.php?action=login`
* **Headers:** `Content-Type: application/json`
* **Request Body:**
```json
{
  "email": "employee@example.com",
  "password": "user_password",
  "device_name": "Samsung Galaxy S23",
  "device_model": "SM-S911B",
  "device_os": "Android 14",
  "device_id": "uuid-generated-on-app-install"
}
```
* **Success Response (200 OK):**
```json
{
  "success": true,
  "message": "Mobile authentication successful.",
  "token": "mrg_3a9f0e1d8c...",
  "token_type": "Bearer",
  "expires_at": "2026-12-31 23:59:59",
  "user": {
    "id": 12,
    "name": "Deepak Awasthi",
    "email": "deepak@example.com",
    "role": "Super Admin",
    "is_admin": true,
    "tenant_id": 1,
    "profile_photo": null
  },
  "company": {
    "name": "Marg Soft Solution HQ",
    "code": "MSS01"
  },
  "effective_shift": {
    "shift_name": "General Shift",
    "shift_start_time": "10:15:00",
    "shift_end_time": "20:00:00",
    "grace_period_minutes": 15
  }
}
```
* **Brute-Force Lockout (429 Too Many Requests):**
```json
{
  "success": false,
  "message": "Account temporarily locked due to 5 consecutive failed login attempts. Please try again in 15 minutes."
}
```

---

### Endpoint 2: Today Punch Status & Live Clock
* **Method:** `GET`
* **Path:** `/api/attendance.php?action=status`
* **Headers:** `Authorization: Bearer <token>`
* **Success Response (200 OK):**
```json
{
  "success": true,
  "current_time_ist": "2026-11-01 10:30:00",
  "today_date": "2026-11-01",
  "user": {
    "id": 12,
    "name": "Deepak Awasthi",
    "is_admin": true
  },
  "settings": {
    "office_name": "Marg Soft Solution HQ",
    "office_lat": 28.613939,
    "office_lng": 77.209021,
    "geofence_radius_meters": 150,
    "require_gps": 1,
    "require_selfie": 1
  },
  "user_shift": {
    "is_custom": false,
    "shift_name": "General Shift",
    "shift_start_time": "10:15:00",
    "shift_end_time": "20:00:00",
    "grace_period_minutes": 15
  },
  "punch": {
    "id": 105,
    "checkin_time": "2026-11-01 10:18:22",
    "checkout_time": null,
    "checkin_approval_status": "auto_approved",
    "status": "present"
  },
  "running_seconds": 718
}
```

---

### Endpoint 3: Punch Check-In
* **Method:** `POST`
* **Path:** `/api/attendance.php?action=checkin`
* **Headers:**
  - `Authorization: Bearer <token>`
  - `Content-Type: application/json`
* **GPS Accuracy Pre-Check in App:**
  - Require `position.accuracy <= 300` meters.
  - If `accuracy > 300` (cell tower triangulation), prompt the user to enable Wi-Fi / High Accuracy mode before dispatching request.
* **Request Body:**
```json
{
  "lat": 28.613939,
  "lng": 77.209021,
  "accuracy": 14.5,
  "selfie": "data:image/jpeg;base64,...",
  "reason": "Outside perimeter client meeting"
}
```
* **Success Response (200 OK):**
```json
{
  "success": true,
  "message": "Check-in successful. Office geofence verified (Distance: 24.3 meters). Have a great shift!",
  "distance": 24.3,
  "checkin_time": "2026-11-01 10:18:22"
}
```
* **Outside Geofence Perimeter Warning (422 Unprocessable Entity):**
```json
{
  "success": false,
  "require_reason": true,
  "distance": 842.1,
  "radius": 150,
  "message": "You are outside the office perimeter (Distance: 842.1 meters, Allowed: 150 meters). Please provide a reason for Super Admin verification."
}
```
*(When `require_reason: true` is received, display a reason input modal to the employee and resubmit with the `reason` field).*

---

### Endpoint 4: Punch Check-Out
* **Method:** `POST`
* **Path:** `/api/attendance.php?action=checkout`
* **Headers:**
  - `Authorization: Bearer <token>`
  - `Content-Type: application/json`
* **Request Body:**
```json
{
  "lat": 28.613939,
  "lng": 77.209021,
  "accuracy": 12.0,
  "selfie": "data:image/jpeg;base64,...",
  "reason": ""
}
```

---

### Endpoint 5: Attendance History & Leaves
* **Monthly Attendance History:** `GET /api/attendance.php?action=my_history`
* **Apply Leave:** `POST /api/attendance.php?action=apply_leave`
  - Body:
    ```json
    {
      "leave_type": "casual",
      "start_date": "2026-11-05",
      "end_date": "2026-11-06",
      "reason": "Family function"
    }
    ```

---

### Endpoint 6: Lead Management
* **Get Assigned Leads:** `GET /api/leads.php`
* **Headers:** `Authorization: Bearer <token>`
* Automatically isolates leads based on authenticated employee credentials (`isAdmin` sees company leads, standard staff sees assigned leads).

---

### Endpoint 7: Device Logout & Security Revocation
* **Single Device Logout:** `POST /api/mobile_auth.php?action=logout`
* **Revoke All Devices (Lost Phone Shield):** `POST /api/mobile_auth.php?action=revoke_all`
* **Active Devices Audit:** `GET /api/mobile_auth.php?action=active_devices`

---

## 6. Recommended Modular Project Structure

```text
lib/
├── core/
│   ├── api/
│   │   ├── api_client.dart            # Dio client with Bearer Token interceptor
│   │   └── api_endpoints.dart         # Base URL and API route definitions
│   ├── storage/
│   │   └── secure_storage_service.dart # FlutterSecureStorage wrapper for tokens
│   ├── theme/
│   │   └── app_theme.dart             # Enterprise color scheme, fonts, borders
│   └── utils/
│       ├── geolocation_helper.dart    # GPS accuracy verification & lock
│       └── image_compressor.dart      # Front-camera compression (JPEG 70% quality)
├── features/
│   ├── auth/
│   │   ├── controllers/auth_controller.dart
│   │   └── views/login_view.dart
│   ├── attendance/
│   │   ├── controllers/attendance_controller.dart
│   │   ├── views/punch_console_view.dart
│   │   ├── views/camera_capture_view.dart
│   │   ├── views/attendance_history_view.dart
│   │   └── views/apply_leave_view.dart
│   ├── dashboard/
│   │   └── views/main_shell_view.dart # Bottom navigation bar with Lucide icons
│   ├── leads/
│   │   ├── controllers/leads_controller.dart
│   │   └── views/leads_list_view.dart
│   └── profile/
│       └── views/security_devices_view.dart # Audit logged-in devices & remote wipe
└── main.dart                          # App bootstrapping, theme configuration, auto-login check
```

---

## 7. Implementation Checklist

- [ ] Automatic token check on cold app start (auto-navigate to Dashboard if valid, else Login).
- [ ] Front camera preview with face guide circle and high-compression selfie capture.
- [ ] Geolocation threshold enforcement (reject coarse cell tower locations > 300m).
- [ ] Clean error handling on network drops with reload triggers.
- [ ] Single-click device session termination from the Security & Devices view.
- [ ] Zero emojis across all screens and messages.

