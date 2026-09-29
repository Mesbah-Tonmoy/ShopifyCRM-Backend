### **Project Documentation: Shopify Multi-App CRM**

#### **1. Project Overview**

The goal is to build a centralized Customer Relationship Management (CRM) system to aggregate installation data from multiple Shopify apps. The system will provide a unified dashboard to view and filter this data. It will also include administrative features to manage the connected Shopify apps and configure app-specific settings, such as email templates.

**Core Features:**
1.  **Secure Webhook Endpoint:** To receive and process installation data from external Shopify apps.
2.  **Data Storage:** Persist app installation details (shop domain, contact info, plan, etc.) in a database.
3.  **Admin Dashboard:** A Vue.js frontend to display all installation records.
4.  **App-wise Filtering:** Allow users to filter the installation data based on the originating Shopify app.
5.  **App Management:** A UI to add, view, and manage the Shopify apps connected to the CRM.
6.  **Settings Module:** A settings page to configure email templates for each individual Shopify app.

#### **2. Technology Stack**

*   **Backend:** Laravel 12
*   **Frontend:** Vue 3
*   **Styling:** Tailwind CSS 4
*   **Database:** MySQL or PostgreSQL (as configured in Laravel)
*   **Containerization:** Docker

#### **3. Database Schema Design**

We'll need three primary tables to start:

**a) `shopify_apps`**
This table will store the details of the Shopify apps you want to manage within the CRM.

| Column        | Type                  | Notes |
| :------------ | :------------         | :---- |
| `id`          | `bigIncrements`       | Primary Key |
| `name`        | `string`              | e.g., "My Awesome SEO App" |
| `api_key`     | `string`, `unique`    | A unique, random key you generate to authenticate incoming webhooks. |
| `api_secret`  | `string`              | A secret key for potential future HMAC verification. |
| `created_at`  | `timestamp`           | |
| `updated_at`  | `timestamp`           | |

**b) `installations`**
This table will store the installation data received from your apps.

| Column            | Type                      | Notes |
| :---------------- | :----------------         | :---- |
| `id`              | `bigIncrements`           | Primary Key |
| `shopify_app_id`  | `foreignId`               | Foreign key referencing `shopify_apps.id`. |
| `shop_domain`     | `string`                  | e.g., "my-cool-store.myshopify.com" |
| `access_token`    | `text`, `encrypted`       | The Shopify access token. **MUST be encrypted.** |
| `plan_name`       | `string`, `nullable`      | The subscription plan name. |
| `contact_email`   | `string`, `nullable`      | The store's contact email. |
| `installed_at`    | `timestamp`               | |
| `uninstalled_at`  | `timestamp`, `nullable`   | To track app uninstalls. |
| `created_at`      | `timestamp`               | |
| `updated_at`      | `timestamp`               | |

**c) `email_templates`**
This table will store the email bodies for different events on a per-app basis.

| Column            | Type                  | Notes |
| :---------------- | :----------------     | :---- |
| `id`              | `bigIncrements`       | Primary Key |
| `shopify_app_id`  | `foreignId`           | Foreign key referencing `shopify_apps.id`. |
| `template_name`   | `string`              | e.g., "welcome_email", "uninstall_feedback" |
| `subject`         | `string`              | The email subject line. |
| `body`            | `text`                | The email body content (can be HTML). |
| `created_at`      | `timestamp`           | |
| `updated_at`      | `timestamp`           | |

#### **4. API Endpoint Design (in `routes/api.php`)**

**a) Webhook for Data Ingestion**
This is the public-facing endpoint your Shopify apps will call.

*   **Endpoint:** `POST /v1/installations`
*   **Authentication:** The calling app must include the `api_key` from the `shopify_apps` table in the request header (e.g., `X-Shopify-App-Key: <your_generated_api_key>`).
*   **Request Body:**
    ```json
    {
      "shop_domain": "new-store.myshopify.com",
      "access_token": "shpua_...",
      "plan_name": "premium",
      "contact_email": "owner@new-store.com"
    }
    ```
*   **Action:** Validates the API key, then creates or updates a record in the `installations` table.

**b) Endpoints for Frontend Consumption (Authenticated)**
These endpoints will be used by your Vue frontend and should be protected by Laravel Sanctum.

*   **Shopify App Management:**
    *   `GET /shopify-apps`: List all managed apps.
    *   `POST /shopify-apps`: Create a new managed app.
    *   `PUT /shopify-apps/{id}`: Update an app's details.
    *   `DELETE /shopify-apps/{id}`: Delete a managed app.

*   **Installations Data:**
    *   `GET /installations`: Get a paginated list of all installations.
    *   `GET /installations?app_id={id}`: Filter installations by a specific Shopify app.

*   **Email Template Management:**
    *   `GET /email-templates?app_id={id}`: Get all templates for a specific app.
    *   `PUT /email-templates/{id}`: Update a specific email template.

---

### **Step-by-Step Development Roadmap**

Here is a logical sequence of steps to build the project.

#### **Phase 1: Backend Setup (Laravel)**

1.  **New Laravel Project:** Create the `backend` project.
2.  **Database Migrations:**
    *   Run `php artisan make:migration create_shopify_apps_table`.
    *   Run `php artisan make:migration create_installations_table`.
    *   Run `php artisan make:migration create_email_templates_table`.
    *   Define the schema in the migration files as detailed above.
3.  **Models:**
    *   Create the models: `ShopifyApp`, `Installation`, `EmailTemplate`.
    *   In the `Installation` model, set up the `access_token` attribute to be automatically encrypted/decrypted:
        ```php
        // app/Models/Installation.php
        use Illuminate\Database\Eloquent\Casts\Attribute;
        use Illuminate\Support\Facades\Crypt;

        protected function accessToken(): Attribute {
            return Attribute::make(
                get: fn ($value) => Crypt::decryptString($value),
                set: fn ($value) => Crypt::encryptString($value),
            );
        }
        ```
    *   Define the model relationships (`ShopifyApp` hasMany `Installations`, etc.).
4.  **Webhook Controller:**
    *   Create `Api/V1/InstallationController.php`.
    *   Implement the `store` method to handle the `POST /v1/installations` request.
    *   Create a custom middleware (`php artisan make:middleware EnsureShopifyAppKeyIsValid`) to validate the `X-Shopify-App-Key` header.
5.  **API Controllers:**
    *   Create API resource controllers for `ShopifyApp` and `EmailTemplate`.
    *   Implement the CRUD methods for each, using Form Requests for validation.
6.  **Routing:**
    *   Define all the endpoints in `routes/api.php`. Group the frontend-facing APIs under the `auth:sanctum` middleware.

#### **Phase 2: Frontend Setup (Vue)**

1.  **New Vue Project:** Create the `frontend` project using `npm create vue@latest`. Select Vue Router and Pinia for state management.
2.  **Install Dependencies:**
    *   `npm install axios tailwindcss postcss autoprefixer`
    *   `npx tailwindcss init -p`
3.  **Configure Tailwind:** Configure `tailwind.config.js` and `main.css` to include Tailwind's base styles.
4.  **API Service:** Create a file (`src/services/api.js`) to centralize `axios` calls to your Laravel backend. Configure it to handle base URLs and authentication tokens.
5.  **Routing:** Set up `src/router/index.js` with routes for the dashboard, app management, and settings pages (e.g., `/`, `/apps`, `/settings/:appId`).
6.  **State Management (Pinia):** Create stores for `apps` and `installations` to hold data fetched from the API.

#### **Phase 3: Feature Implementation (Connecting Frontend & Backend)**

1.  **Authentication:** Implement login/logout functionality for the CRM itself using Laravel Sanctum and your Vue frontend.
2.  **App Management UI:**
    *   Create a page (`/apps`) that lists the Shopify apps from `GET /shopify-apps`.
    *   Build a form/modal to add a new app (`POST /shopify-apps`), which upon creation should display the generated `api_key` for the user to copy.
3.  **Dashboard UI:**
    *   Create the main dashboard view (`/`).
    *   Fetch all installations from `GET /installations` and display them in a table.
    *   Create a dropdown component populated with data from the apps store (Pinia). When an app is selected, re-fetch data using `GET /installations?app_id={id}`.
4.  **Settings UI:**
    *   Create the settings page (`/settings/:appId`).
    *   Use the `appId` from the route parameter to fetch the correct email templates (`GET /email-templates?app_id={id}`).
    *   Display each template in a form with fields for subject and body (a `textarea`).
    *   Implement the save functionality to `PUT /email-templates/{id}`.

#### **Phase 4: Finalization**

1.  **Docker Configuration:** Update the `docker-compose.yml` file to run both the Laravel/Nginx container and the Vue development server container, ensuring they can communicate with each other.
2.  **Testing:**
    *   **Backend:** Write feature tests in Laravel to validate your API endpoints, especially the webhook ingestion logic.
    *   **Frontend:** Test all UI interactions, filtering, and form submissions.
3.  **Deployment:** Plan for deployment. You can build the Vue app (`npm run build`) and serve the static files from Laravel itself, or host the frontend and backend separately.
