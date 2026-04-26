# Research Brief: Content Plugin Injection Architecture

## Research Question

How to inject content-type plugins (bbcode, markdown, smiles, censor) to process content before saving or before sending in responses?

## Research Type

Mixed — combines technical codebase analysis with architectural best-practices research.

## Scope

### Included
- Plugin/middleware injection architecture for content processors
- Content processing pipeline (post/message body)
- phpBB 3.x event/hook system patterns (as reference)
- Symfony EventDispatcher and tagged DI services as potential mechanisms
- Pre-save processing (in controller or service layer)
- Pre-response transformation (serializer / serialization listener)
- Extensibility: how to add new processors without touching core

### Excluded
- Frontend-only rendering (React side)
- Raw HTML CSS/styling of processed output

### Constraints
- Must follow `phpbb\` namespace, Symfony 8.x DI, PSR-4
- Must remain compatible with REST API JSON response format
- Must not use PHP `global` anywhere

## Success Criteria

1. Identify where in the request/response cycle content should be processed
2. Identify the best Symfony/phpBB4 mechanism for plugin registration (tagged services, events, middleware)
3. Identify how phpBB 3.x handled this (for pattern reference)
4. Produce a recommended injection architecture with clear extension points
5. Address both pre-save AND pre-output transformation use cases
