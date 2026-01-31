Backend repo: Trendsetter-Core (Laravel)
========================================

Data changes
------------

1.  Update goals table:
    

*   add completed\_at nullable timestamp
    
*   add completion\_reason nullable string (store points\_threshold, end\_date\_passed, or both)
    
*   ensure status enum already supports completed (it does)
    

1.  Add goal\_reviews table:
    

*   id, goal\_id fk, user\_id fk
    
*   outcome enum: achieved, partially\_achieved, not\_achieved
    
*   feelings json (array of strings)
    
*   why text, wins text, obstacles text, lessons text, next\_steps text, advice text nullable
    
*   stats\_snapshot json nullable (store points/stats at time of review so it never changes)
    
*   created\_at, updated\_at
    

Immutability enforcement:

*   On any goal update endpoints (if exist), reject edits when status=completed or completed\_at != null
    

Points + eligibility computation (server-side)
----------------------------------------------

Implement a single service, eg GoalCompletionService, that returns:

*   points\_earned
    
*   max\_possible\_points
    
*   threshold\_points (ceil or round, pick one and be consistent, default ceil)
    
*   is\_completable
    
*   completion\_reasons array
    

It should compute:

*   points\_earned from event\_feedback joined to events for that goal using your existing mapping
    
*   max\_possible\_points using repeat schedule approximation if you don’t store instances, else true occurrences
    
*   end date rule: now >= end\_date + 24h
    

Expose these computed fields in:

*   GET /goals response per goal
    
*   GET /goals/{goal} if you have it
    

Endpoints (auth:sanctum)
------------------------

Add:

*   POST /goals/{goal}/complete
    
    *   checks ownership
        
    *   checks eligibility, if not eligible return 422 with reasons
        
    *   sets goal status completed + completed\_at + completion\_reason
        
    *   returns goal including computed fields
        
*   POST /goals/{goal}/review
    
    *   requires goal is completed
        
    *   review is one-time: if exists, reject with 409 (immutable review)
        
    *   store review + stats\_snapshot
        
    *   return review
        
*   GET /goals/completed
    
    *   returns completed goals + review summary (outcome, completed\_at)
        
*   GET /goals/{goal}/review
    
    *   returns review (or 404 if none)
        

Validation
----------

*   feelings must be array of allowed strings (define a whitelist)
    
*   long text limits sane (eg 5000 chars)
    
*   outcome required