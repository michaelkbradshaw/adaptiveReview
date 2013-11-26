cumulative-quiz
================

A set of Moodle Module to create a cumulative quiz activity


Cumulative Report
------------------

This has no effect when outside of a course page.



Storing grades for your cumulative quiz on a quiz by quiz basis
----------------------------------------------------------------

When the cumulative quiz determines that a user has received grade G on 
questions from quiz Q, it will search for assignments and update them based on their name.  There are two cases.


Cumulative Q     == records all scores
Complete G For Q  == only record grade G and nothing else


Storing by group
----------------

If you are working with groups, you can add the group name to the assignments to only save for that group.  For instance, if Groups A and B exist

Cumulative Q A    == records all scores for group A
Complete G For Q  B == only record grade G and for group B and nothing else


