# AI Integration Contract

## Principle

The AI integration must create a change plan. It must not perform a production WooCommerce write directly for operations covered by this workflow.

### Create

POST /wp-json/aiwc/v1/tasks

Required:
- schema_version = 1
- task_type
- title
- changes[]

Each change:
- object_type = product
- object_id = positive integer
- field = one of the V1 supported fields
- before
- after

The server compares before to the current WooCommerce value and stores a server snapshot.

### Read

GET /wp-json/aiwc/v1/tasks/{id}

The response includes task metadata, current task status, plan and audit history.

### Human approval

POST /wp-json/aiwc/v1/tasks/{id}/approve

This endpoint is for an authenticated WordPress user with aiwc_approve_tasks.

The server checks:
- task is pending_review
- task is not expired
- stored plan hash still matches
- every current WooCommerce value still equals the stored before value

Any mismatch changes the task to stale.

### Rejection

POST /wp-json/aiwc/v1/tasks/{id}/reject

Body:
{"reason":"..."}

### Execute

POST /wp-json/aiwc/v1/tasks/{id}/execute

Only an authenticated user with aiwc_execute_tasks may execute an already approved task. Execution is claimed atomically and each product is revalidated immediately before its write.

## Important deployment rule

The credential used by an AI agent must not be a WordPress account that also has approval/execution capabilities if the goal is strict separation between AI proposal and human approval. Create a dedicated low-privilege integration identity and grant it only the minimum capabilities needed to create/read plans.
