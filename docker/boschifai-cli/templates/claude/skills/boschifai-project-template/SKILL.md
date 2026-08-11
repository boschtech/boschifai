---
name: boschifai-project-template
description: "Template for adding project-specific skills — copy this folder and customize for each project"
---

# Project-Specific Skill Template

Copy this skill folder and customize it for each project you onboard to Boschifai. This defines project-specific patterns, conventions, and design rules that override or extend the default Boschifai skills.

## How to Add a New Project Skill

1. Copy this folder: `.claude/skills/boschifai-project-template/` to `.claude/skills/boschifai-<project-name>/`
2. Rename the `name` field in the frontmatter
3. Fill in all sections below with your project specifics
4. The skill will be automatically picked up by Claude Code and Copilot

---

## Template (replace everything below with your project specifics)

### Architecture Overview

- Frontend: <framework, component library>
- Backend: <language, framework, service pattern>
- API style: <REST/GraphQL/gRPC>
- Database: <type>
- Deployment: <cloud/on-prem, CI/CD tool>

### Project-Specific Test Rules

#### Naming
- Component test files: `<component>.test.tsx`
- API test files: `<endpoint>.api.test.ts`
- E2E test files: `<journey>.e2e.test.ts`

#### Required Coverage
- Unit: <X>%
- Integration: <X>%
- E2E: <list of mandatory journeys>

#### Environments
- Dev: <URL>
- QA: <URL>
- Staging: <URL>
- Storybook: <URL>

#### External Dependencies
- <API name>: <mocked/real in which env>
- <Service name>: <status>

#### Design System / Component Library
- Library: <name>
- Storybook: <URL>
- Visual baseline: <tool>

#### Jira Project Keys
- <PROJECT_KEY> — <description>

#### Custom Field Mappings (if different from defaults)
- Story Points: `customfield_XXXXX`
- Epic Link: `customfield_XXXXX`

#### Team Conventions
- PR review required before: <what>
- Test evidence required for: <what change types>
- Accessibility standard: <WCAG level>
- Browser support: <list>

#### Known Patterns to Follow
- <Pattern 1>: <description and when to apply>
- <Pattern 2>: <description and when to apply>

#### Known Anti-Patterns to Avoid
- <Anti-pattern 1>: <why it's bad>
- <Anti-pattern 2>: <why it's bad>
