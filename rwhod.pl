#!/usr/bin/env perl
# rwho data collector daemon
use warnings;
use strict;
use Data::Dumper;
use Getopt::Long qw(:config no_ignore_case bundling);
use JSON;
use LWP::UserAgent;
use Linux::Inotify2;
use POSIX qw(:errno_h :signal_h);
use Pod::Usage;
use Sys::Hostname;

my $notify_url = "http://equal.cluenet.org/rwho/server.php";
my $utmp_path;
my $poll_interval;
my $verbose	= 0;
my $do_fork	= 0;
my $do_single	= 0;
my $do_inotify	= 1;
my $do_poll	= 1;
my $show_root	= 0;
my $pidfile;
my $pidfile_h;

my $my_hostname;
my $my_fqdn;
my $poller_pid;

sub canon_hostname {
	my $host = shift;
	if (eval {require Socket::GetAddrInfo}) {
		debug("canon_hostname: using Socket::GetAddrInfo");
		my %hint = (flags => Socket::GetAddrInfo->AI_CANONNAME);
		my ($err, @ai) = Socket::GetAddrInfo::getaddrinfo($host, "", \%hint);
		# FIXME: print error messages when needed
		return $err ? $host : ((shift @ai)->{canonname} // $host);
	}
	elsif (eval {require Net::addrinfo}) {
		debug("canon_hostname: using Net::addrinfo");
		my $hint = Net::addrinfo->new(
			flags => Net::addrinfo->AI_CANONNAME);
		my $ai = Net::addrinfo::getaddrinfo($host, undef, $hint);
		return (ref $ai eq "Net::addrinfo") ? $ai->canonname : $host;
	}
	else {
		debug("canon_hostname: using \"getent hosts\"");
		open my $fd, "-|", "getent", "hosts", $host;
		my @ai = split(" ", <$fd>);
		close $fd;
		return $ai[1] // $host;
	}
}

# Read the utmp file
sub enum_sessions {
	my @utmp = ();
	if (eval {require User::Utmp}) {
		debug("enum_sessions: using User::Utmp");
		User::Utmp::utmpxname($utmp_path);
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
		debug("enum_sessions: using Sys::Utmp");
		my $utmp = Sys::Utmp->new(Filename => $utmp_path);
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
		die "error: either User::Utmp or Sys::Utmp required\n";
	}
	return @utmp;
}

# "utmp changed" handler
sub update {
	my @sessions = enum_sessions();
	for (@sessions) {
		$_->{uid} = scalar getpwnam $_->{user};
		$_->{host} =~ s/^::ffff://;
	}
	if (!$show_root) {
		@sessions = grep {$_->{user} ne "root"} @sessions;
	}
	debug("update: uploading ".scalar(@sessions)." items");
	upload("put", \@sessions);
}

# Upload data to server
sub upload {
	my ($action, $sessions) = @_;
	my $ua = LWP::UserAgent->new;

	my %data = (
		host => $my_hostname,
		fqdn => $my_fqdn,
		action => $action,
		utmp => encode_json($sessions),
	);
	my $resp = $ua->post($notify_url, \%data);

	if ($resp->is_success) {
		debug("upload: ".$resp->status_line);
	} else {
		warn "upload error: ".$resp->status_line."\n";
	}
}

# Main loops

sub watch_inotify {
	$0 = "rwhod: inotify($utmp_path)";

	my $inotify = Linux::Inotify2->new();
	$inotify->watch($utmp_path, IN_MODIFY, \&update);

	debug("watch: idling");
	while (1) {
		$inotify->poll;
	}
}

sub watch_poll {
	$0 = "rwhod: poll(${poll_interval}s)";

	debug("poll: updating every $poll_interval seconds");
	while (1) {
		sleep $poll_interval;
		update();
	}
}

# Utility functions

sub debug {
	local $" = " ";
	$verbose and print "rwhod[$$]: @_\n";
}

sub getutmppath {
	my @paths = qw(
		/run/utmp
		/etc/utmp
		/var/run/utmp
	);
	my ($path) = grep {-e} @paths;
	if (defined $path) {
		debug("getutmppath: path=$path");
	} else {
		die("getutmppath: utmp not found\n");
	}
	return $path;
}

sub forked(&) {
	my $sub = shift;
	my $pid = fork();
	if ($pid) {return $pid} else {exit &$sub}
}

sub daemonize {
	chdir("/")
		or die "can't chdir to /: $!";
	open(STDIN, "<", "/dev/null")
		or die "can't read /dev/null: $!";
	open(STDOUT, ">", "/dev/null")
		or die "can't write /dev/null: $!";

	my $pid = fork;

	if (!defined $pid) {
		die "can't fork: $!";
	} elsif ($pid) {
		debug("forked to $pid");
		exit;
	} else {
		if (POSIX::setsid() < 0) {
			warn "setsid failed: $!";
		}
		debug("running in background");
	}
}

# Process management functions

sub fork_poller {
	debug("starting poller");
	$SIG{CHLD} = \&sigchld_reap_poller;
	return forked {
		$SIG{INT} = "DEFAULT";
		$SIG{TERM} = "DEFAULT";
		$SIG{CHLD} = \&sigchld_reap_any;
		watch_poll();
	};
}

sub cleanup {
	if (defined $poller_pid) {
		debug("cleanup: killing poller");
		$SIG{CHLD} = \&sigchld_reap_any;
		kill(SIGTERM, $poller_pid);
	}
	debug("cleanup: removing sessions on server");
	upload("destroy", []);
	exit;
}

sub sigchld_reap_poller {
	my $pid = wait;
	$SIG{CHLD} = \&sigchld_reap_poller;
	if (defined $poller_pid and $pid == $poller_pid) {
		debug("poller exited, restarting");
		$poller_pid = fork_poller();
	} else {
		debug("received SIGCHLD for unknown pid $pid");
	}
}

sub sigchld_reap_any {
	my $pid = wait;
	$SIG{CHLD} = \&sigchld_reap_any;
	debug("received SIGCHLD for $pid");
}

# Initialization code

GetOptions(
	"d|daemon"	=> \$do_fork,
	"help"		=> sub { pod2usage(1); },
	"include-root!"	=> \$show_root,
	"inotify!"	=> \$do_inotify,
	"i|interval=i"	=> \$poll_interval,
	"man"		=> sub { pod2usage(-exitstatus => 0,
				-verbose => 2); },
	"pidfile=s"	=> \$pidfile,
	"server-url=s"	=> \$notify_url,
	"single!"	=> \$do_single,
	"v|verbose"	=> \$verbose,
) or pod2usage(1);

if (!defined $notify_url) {
	die "error: notify_url not specified\n";
}

# use large interval if inotify is available
$poll_interval //= ($do_inotify ? 600 : 30);

$do_poll = $poll_interval > 0;

unless ($do_inotify || $do_poll) {
	die "error: cannot disable both poll and inotify\n";
}

$0 = "rwhod";

$my_hostname = hostname();
$my_fqdn = canon_hostname($my_hostname);
debug("identifying as \"$my_fqdn\" ($my_hostname)");

$utmp_path //= getutmppath();

$SIG{INT} = \&cleanup;
$SIG{TERM} = \&cleanup;

if ($do_single) {
	if ($do_fork) {
		warn "warning: --fork ignored in single-update mode\n";
	}
	debug("doing single update");
	$0 = "rwhod: updating";
	update();
	exit();
}

if (defined $pidfile) {
	open $pidfile_h, ">", $pidfile
		or die "unable to open pidfile '$pidfile'\n";
}

debug("doing initial update");
update();

if ($do_fork) {
	daemonize();
} else {
	chdir("/");
}

if (defined $pidfile_h) {
	print $pidfile_h "$$\n";
	close $pidfile_h;
}

if ($do_inotify) {
	if ($do_poll) {
		$poller_pid = fork_poller();
	}
	debug("starting inotify watch");
	watch_inotify();
}
elsif ($do_poll) {
	debug("starting poller");
	watch_poll();
}

__END__

=head1 NAME

rwhod - remote-who collector daemon

=head1 SYNOPSIS

rwhod [options]

=head1 OPTIONS

=over 8

=item B<-d>, B<--daemon>

Fork to background after initial update.

=item B<--help>

Obvious.

=item B<--[no-]include-root>

Include root logins. By default, disabled.

=item B<--[no-]inotify>

Turn off inotify and only use periodic updates.

=item B<-i I<seconds>>, B<--interval=I<seconds>>

Periodic update every I<seconds> seconds. 600 seconds is the default (30 seconds if inotify is disabled). Zero to disable.

=item B<--man>

Display the manual page.

=item B<--pidfile I<path>>

Write PID to file.

=item B<--server-url I<url>>

Use specified server URL.

=item B<--single>

Do a single update and exit.

=item B<-v>, B<--verbose>

Print informative messages.

=back

=head1 DEPENDENCIES

Perl 5.10, apparently.

B<C<getaddrinfo>>: C<Socket::GetAddrInfo>, C<Net::addrinfo>, or the C<getent> binary.

B<C<utmp> access>: C<Sys::Utmp> or C<User::Utmp>

B<C<inotify>>: C<Linux::Inotify2> for real-time C<utmp> monitoring.

B<HTTP>: C<JSON> and C<LWP::UserAgent>

=head1 BUGS

It's useless.

C<inotify> requirement makes the script unportable outside Linux.

Using C<getaddrinfo> just to find our own FQDN might be overkill when the rest of the world can use C<gethostbyaddr> on 127.0.0.1.

Data submission URL is hardcoded.

Hosts are only identified by their FQDN, so it's possible to upload fake data.

Incremental updates are not yet implemented (server-side support exists).

=cut
