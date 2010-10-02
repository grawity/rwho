#!/usr/bin/perl
# rwho data collector daemon

# Debian: liblinux-inotify2-perl libjson-perl libjson-xs-perl libsys-utmp-perl
use warnings;
use strict;

use constant PATH_UTMP => '/var/run/utmp';
use constant {
	NOTIFY_URL => 'http://equal.cluenet.org/~grawity/misc/rwho/server.php',
};

use POSIX qw(signal_h);
use Linux::Inotify2;
use Sys::Hostname;
use JSON;
use LWP::UserAgent;

my $my_hostname;
my $pid_periodic;

sub forked(&) {
	my $sub = shift;
	my $pid = fork();
	if ($pid) {return $pid} else {exit &$sub}
}

sub ut_dump() {
	my @utmp = ();
	if (eval {require User::Utmp}) {
		while (my $ent = User::Utmp::getutxent()) {
			if ($ent->{ut_type} == User::Utmp->USER_PROCESS) {
				push @utmp, {
					user => $ent->{ut_user},
					line => $ent->{ut_line},
					host => $ent->{ut_host},
					time => $ent->{ut_time},
				};
			}
		}
		User::Utmp::endutxent();
	}
	elsif (eval {require Sys::Utmp}) {
		my $utmp = Sys::Utmp->new();
		while (my $ent = $utmp->getutent()) {
			if ($ent->user_process) {
				push @utmp, {
					user => $ent->ut_user,
					line => $ent->ut_line,
					host => $ent->ut_host,
					time => $ent->ut_time,
				};
			}
		}
		$utmp->endutent();
	}
	else {
		die "fatal: either User::Utmp or Sys::Utmp required\n";
	}
	return @utmp;
}

sub update() {
	my @data = ut_dump();
	for (@data) {
		$_->{uid} = scalar getpwnam $_->{user};
		$_->{host} =~ s/^::ffff://;
	}
	upload("put", \@data);
}

sub upload($$) {
	my ($action, $data) = @_;
	my $ua = LWP::UserAgent->new;
	my %data = (
		host => $my_hostname,
		action => $action,
		utmp => encode_json($data),
	);
	my $resp = $ua->post(NOTIFY_URL, \%data);
	if (!$resp->is_success) {
		print "error: ".$resp->status_line."\n";
	}
}

sub watch() {
	my $inotify = Linux::Inotify2->new();
	$inotify->watch(PATH_UTMP, IN_MODIFY, sub {
		update();
	});
	1 while $inotify->poll;
}

sub cleanup {
	kill SIGTERM, $pid_periodic unless $pid_periodic == $$;
	upload("destroy", []);
}

$my_hostname = hostname;
$my_hostname =~ s/\..*$//;

$SIG{INT} = \&cleanup;
$SIG{TERM} = \&cleanup;

# initial update
update();

$pid_periodic = forked {
	my $interval = 10*60;

	$0 = "rwhod: periodic(${interval}s)";
	$SIG{INT} = "DEFAULT";
	$SIG{TERM} = "DEFAULT";

	while (1) {
		sleep $interval;
		update();
	}
};

$0 = "rwhod: inotify(".PATH_UTMP.")";
watch();
