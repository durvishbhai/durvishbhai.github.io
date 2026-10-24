# 22 · Requesting a new project from the client dashboard

## For the client
*Dashboard → **Request a new project*** (also *Sales & clients → Request a project*). The form is the website's requirements form without the identity fields: the client is already known, so name, email, phone and company come from their record. They give the project name and category, the services they need, the problem, the expected outcome, optional features, technology, budget, timeline, a "needed by" date, up to 3 attachments and notes. The list under *My project requests* shows where each request stands: **In review**, **Project created** (waiting for the advance payment), **Confirmed**, or **Closed**.

## For the team
A request is an ordinary CRM enquiry with the source **Client dashboard request**, already linked to the client (no duplicate warning against their own earlier requests). The team works it as usual: contact, mark **Won**, **Convert**. Converting a client-dashboard request uses the client's own record; no second client is created. Then: quotation → invoice for the advance → payment request.

## "Fixed when the advance payment is done"
A project is fixed (status **Ready to Start**) **automatically** the moment its **advance** payment is *verified*:
* *Payment requests* on an invoice that belongs to a project have a field **What is this payment?** (a payment / **the advance** / the balance). Online payments marked as the advance are recorded as the advance when Razorpay confirms them.
* A payment recorded by hand (*Client payments*) already has a payment type; when someone verifies one typed **Advance**, the project is fixed.
* It only moves a project that is still **Draft**, **Awaiting Client Approval** or **Awaiting Payment**. A project that is already Ready to Start, In Progress or later is never touched, and a payment recorded but not yet verified changes nothing. A payment that is not marked as the advance never confirms a project, and an invoice without a project cannot be an advance.
* The change is written to the project's history and the audit log (as "advance payment … verified"), the client is told "Your project is confirmed", and the people who assign projects are told to assign the team.

Permission: the client side uses the existing `requirements.submit` (clients only). Migration `0026_client_project_requests.sql` adds the enquiry source and `gateway_orders.payment_role`.

## The step-by-step requirements form
The website form (`/request-quote`) and the client dashboard form (**Request a new project**) are the same five-step wizard (`partials/requirements-wizard.php`): **1 Type & Basics** (project type, services, your details, project name) → **2 Project Details** (the problem, expected outcome, preferred technology, budget, timeline) → **3 Features** (tick-list plus anything else) → **4 Roles** (who will use it, how many users, what each can do) → **5 Review & send** (all answers listed, attachments, notes, consent). Each step is checked before moving on; the numbers at the top go back to any step. Without JavaScript the same form shows every step on one page and still works. The team sees every answer in the enquiry's details. Clients also get status chips (All / In review / Project created / Confirmed / Closed) and a search box on **My project requests**. Not included: the AI chat assistant and "Improve with AI" buttons of the example form (they need an AI key and cost controls for visitors; the AI helper for the team is unchanged).
