Design a AI gateway for handling agents (claude) accees to Odoo to handle business tasks. The point of the gateway is to firstly make the connections between business applications, and then handle the rules for what agent is allowed to change itself, what needs user approval and what needs to be stopped fully. First function we are implementing is a inbound purchasing email, where suppliers send emails about purchases and the task is to go through them and see what needs updating in odoo.



There needs to be following:

1. MCP connection using the laravel MCP package, and a way to connect Claude to this. For this first version, we don't need a login page. Everything will be done as Riku from purchasing policy, so that can be hardcoded.
2. Gateway desisions. The gateway needs to verify the proposals and rules, such that the agent cannot do functions its not supposed to. This is the most important part
3. Connection to the Odoo instance in docker and writing the approved proposals to it through the API.
4. Approval UI in web interface, and way to look at old approvals.
5. Claude UI from the MCP server to show the proposals in claude chat.  Cards approve and reject buttons go directly to the gateway and never through an MCP tool, such that the agent cannot approve its own proposals.
6. Audit trail every operation into the database, who did what should be logged and viewable on the gateway UI.



Tool design:

The tools are general, everything that instructs the agent should come from the policy, rules, and the skill such that that part is easy to extend and modify. However no system is needed for this and they can be hardcoded for now. Each system gets its own tools, for this first version its odoo and mailbox. The propose functionality stays general for all systems. 

- whoami to get the user. For this first version its always riku.
- get_policy to read the purchasing policy and a plain description of the rules.
- mail_list_messages to list the mailbox. Each message shows the proposals that cite it and their status, so the agent sees which mail is already handled.
- mail_read_message to read one message with the text of its attachments.
- odoo_search_read to read Odoo by model, domain and fields, limited to what rules/[agent-access.md](http://agent-access.md) allows. Purchase order lines come back with their pending and applied proposals attached.
- propose to propose one operation on one record, with the message and the exact quote the values come from. The gateway answers with the decision, the rule or policy section behind it, and what happens next.
- list_proposals to list proposals by status, with who decided, when and why.



The gateway decides in code if a proposal is applied right away by a rule, waits for Riku to approve it on the web page, or is denied by the policy, and when Riku approves the gateway applies it itself, so the agent never commits or approves anything.

The agent remembers nothing between conversations, so the read tools return the proposals that touch each message and order line with their status, and propose returns the existing proposal instead of creating a duplicate.

Rewrite skills/inbound-exceptions/[SKILL.md](http://SKILL.md) to these tools and this flow as it describes an old flow. 

The mailbox is not a real mail connection in this version. The gateway reads the .eml files of one case from cases/, for now cases/demo/demo-mailbox/messages, and Odoo is seeded with the same case using bin/odoo seed. The mail tools should not know that the mail comes from files, so that a real mailbox can replace them later.



Database design:

Use SQLite which is already installed. The gateway uses the source systems for most data, and its database should only include tables for the proposals and the audit log. Audit log should only be appended to.

&nbsp;

ground rules:

1. The laravel boost mcp should be used to follow best practicves
2. Simplicity is required. This is just a first version of the app, so we want to build a strong core functionality, but all edge cases don't need to be handled yet. Code should be clean and extendable in the future. Espesially rules and policies, which should live in one place for each process and for them to be easy to be added
3. Use the newest MCP version from july, if laravel mcp supports that.
4. Note that approval rules is outdated, keep its rules and conditions, but use single proposals instead of proposals, no commit step, and leave out method calls, notes and to-dos for now.



coding:

1. Design testing as well, such that we don't just spam unit tests everywhere, but only the most critical parts are tested. The rules from [approval-rules.md](http://approval-rules.md) and all of the MCP endpoints should be tested. For each test, the ask needs to be what bug does this  catch that other tests don't handle yet, if that cannot be named, the test should not be added.
2. All errors should be handled and logged in the way that PHP guidelines suggest, such that nothing is missed.



Desing well first, use subagents to research online for seperate tasks. For example the MCP UI needs to be well researched how it should be done. But before writing the design after the research pass, use the grill-me skill to go through the proposed design, and if there are relevant alternatives that should be taken care of or if you disagree with some of the direction outlined here. 