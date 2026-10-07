---
status: accepted
---

# Read one skill resource at a time as text

Live trials showed agents describing delegated skills without loading them,
inventing resource paths inside batches and transforming paths represented in
nested JSON results. Use `skill(name)` for the complete skill document and
`skill_resource(name, path)` for one supporting file returned as complete text.
Preparation and storage ownership remain as defined in ADR-0001.

A resource's name and path are already present in the call, so its result can
be the file contents without a collection wrapper. Single-file reading lets the
agent obtain a reference's contents before selecting the next actual path;
it gives up batch reads in exchange for a smaller interface. The toolkit
retains literal-path validation, source selection and explicit read errors.

The toolkit's guidelines and skill tool description require actual calls for
skills named by loaded instructions, before replying or asking clarifications.
Required file paths come from content already read; scripts still use the separate
preparation and execution tools. Generic skill documents are unchanged. No prose
parser, automatic dependency loading or model-response enforcement is introduced.

The selected prototype passed 39/39 recorded cases, including skill delegation,
a database reference and nested filesystem references with literal Unicode paths.
Other variants passed initial trials and failed later confirmations, so these
results support this choice without guaranteeing future model behaviour. This
replaces the earlier batch-reading interface without compatibility aliases.
